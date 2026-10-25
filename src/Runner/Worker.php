<?php

declare(strict_types=1);

namespace Vau\Runner;

use Vau\Build\BuildQueue;
use Vau\Database\Repositories;
use Vau\Support\Logger;

/**
 * Processo de longa duração que consome a fila. Rode quantos quiser, na
 * mesma máquina ou em outras apontando para o mesmo banco: cada build é
 * reservado por um único worker. SIGTERM e SIGINT terminam o build atual
 * antes de sair (desligamento gracioso).
 */
final class Worker
{
    private bool $stopping = false;
    private string $id;

    public function __construct(
        private readonly BuildQueue $queue,
        private readonly BuildRunner $runner,
        private readonly Repositories $repos,
        private readonly Logger $logger,
        private readonly int $sleepSeconds = 2,
        private readonly int $staleSeconds = 120,
    ) {
        $this->id = substr((string) gethostname(), 0, 40) . '-' . getmypid() . '-' . bin2hex(random_bytes(3));
    }

    public function id(): string
    {
        return $this->id;
    }

    /**
     * @param callable(string): void $say saída para o terminal
     */
    public function loop(bool $once, callable $say, ?int $maxBuilds = null): int
    {
        $this->installSignals();
        $workers = $this->repos->workers();
        $workers->register($this->id, (string) gethostname(), (int) getmypid());
        $say("Worker {$this->id} aguardando builds (Ctrl+C para sair).");
        $done = 0;
        $lastMaintenance = 0;

        try {
            while (!$this->stopping) {
                if (time() - $lastMaintenance >= 30) {
                    $lastMaintenance = time();
                    $recovered = $this->queue->recoverStale($this->staleSeconds);
                    if ($recovered > 0) {
                        $say("{$recovered} build(s) órfão(s) encerrado(s) com erro.");
                    }
                    $workers->pruneStopped();
                }
                $workers->heartbeat($this->id, 'idle', null);
                $buildId = $this->queue->claim($this->id);
                if ($buildId === null) {
                    if ($once) {
                        break;
                    }
                    $this->sleep($this->sleepSeconds);
                    continue;
                }
                $say("Build {$buildId} iniciado.");
                $heartbeat = $this->startHeartbeat($buildId);
                try {
                    $status = $this->runner->run($buildId);
                } catch (\Throwable $e) {
                    $status = 'error';
                    $this->logger->error('Worker falhou ao executar o build', ['build' => $buildId, 'exception' => $e]);
                }
                $heartbeat();
                $workers->finished($this->id);
                $say("Build {$buildId} terminou: {$status}.");
                $done++;
                if ($once || ($maxBuilds !== null && $done >= $maxBuilds)) {
                    break;
                }
            }
        } finally {
            $workers->stop($this->id);
        }
        $say('Worker encerrado.');

        return $done;
    }

    /**
     * Mantém o batimento do worker durante o build com um alarme a cada
     * 15 s (com pcntl) para o build não ser tomado como órfão.
     *
     * @return callable(): void desliga o alarme
     */
    private function startHeartbeat(int $buildId): callable
    {
        $workers = $this->repos->workers();
        $workers->heartbeat($this->id, 'busy', $buildId);
        if (!function_exists('pcntl_alarm')) {
            return static function (): void {};
        }
        pcntl_signal(SIGALRM, function () use ($workers, $buildId): void {
            try {
                $workers->heartbeat($this->id, 'busy', $buildId);
            } catch (\Throwable) {
            }
            pcntl_alarm(15);
        });
        pcntl_alarm(15);

        return static function (): void {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, SIG_DFL);
        };
    }

    private function installSignals(): void
    {
        if (!function_exists('pcntl_async_signals')) {
            return;
        }
        pcntl_async_signals(true);
        $handler = function (): void {
            $this->stopping = true;
        };
        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);
    }

    private function sleep(int $seconds): void
    {
        for ($i = 0; $i < $seconds * 10 && !$this->stopping; $i++) {
            usleep(100_000);
        }
    }
}
