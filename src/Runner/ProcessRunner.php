<?php

declare(strict_types=1);

namespace Vau\Runner;

/**
 * Executa um processo sem shell intermediário (comando em array), lê a
 * saída em tempo real pelos dois canais e respeita tempo limite e
 * cancelamento. Valores de ambiente passam pelo env do processo, nunca
 * pela linha de comando, então não aparecem em "ps" nem no log.
 */
final class ProcessRunner
{
    /**
     * @param list<string> $command
     * @param array<string, string> $env variáveis extras (somadas ao ambiente atual)
     * @param callable(string $stream, string $chunk): void $onOutput
     * @param callable(): bool $shouldStop
     * @param (callable(): void)|null $onKill chamado antes de encerrar (ex.: docker kill)
     */
    public static function run(array $command, array $env, ?string $cwd, int $timeoutSeconds, callable $onOutput, callable $shouldStop, ?callable $onKill = null): StepResult
    {
        $started = hrtime(true);
        $fullEnv = array_merge(self::baseEnv(), $env);
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $fullEnv);
        if (!is_resource($process)) {
            $onOutput('err', "Não foi possível iniciar: {$command[0]}\n");

            return new StepResult(127, 0);
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $deadline = $timeoutSeconds > 0 ? microtime(true) + $timeoutSeconds : null;
        $lastStopCheck = 0.0;
        $timedOut = false;
        $canceled = false;
        $open = [1 => $pipes[1], 2 => $pipes[2]];
        $selectFailures = 0;

        while ($open !== []) {
            $read = array_values($open);
            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, 0, 200_000);
            if ($ready === false) {
                // interrompido por sinal (ex.: alarme de batimento do worker); tenta de novo
                if (++$selectFailures > 50) {
                    break;
                }
                continue;
            }
            $selectFailures = 0;
            foreach ($read as $stream) {
                $chunk = fread($stream, 65536);
                $key = array_search($stream, $open, true);
                if ($chunk === false || ($chunk === '' && feof($stream))) {
                    fclose($stream);
                    unset($open[$key]);
                    continue;
                }
                if ($chunk !== '') {
                    $onOutput($key === 1 ? 'out' : 'err', $chunk);
                }
            }
            $now = microtime(true);
            if ($deadline !== null && $now > $deadline) {
                $timedOut = true;
            } elseif ($now - $lastStopCheck >= 1.0) {
                $lastStopCheck = $now;
                $canceled = $shouldStop();
            }
            if ($timedOut || $canceled) {
                if ($onKill !== null) {
                    $onKill();
                }
                self::terminate($process);
                foreach ($open as $stream) {
                    fclose($stream);
                }
                $open = [];
            }
        }

        $status = proc_get_status($process);
        $exit = proc_close($process);
        if ($exit === -1 && !$status['running']) {
            $exit = $status['exitcode'];
        }
        $duration = (int) ((hrtime(true) - $started) / 1_000_000);

        return new StepResult($timedOut ? 124 : ($canceled ? 130 : $exit), $duration, $timedOut, $canceled);
    }

    /**
     * Executa e devolve a saída completa (para comandos curtos como docker version).
     *
     * @param list<string> $command
     * @param array<string, string> $env
     * @return array{exit: int, output: string}
     */
    public static function capture(array $command, array $env = [], ?string $cwd = null, int $timeoutSeconds = 60): array
    {
        $buffer = '';
        $result = self::run($command, $env, $cwd, $timeoutSeconds, function (string $s, string $chunk) use (&$buffer): void {
            if (strlen($buffer) < 1_048_576) {
                $buffer .= $chunk;
            }
        }, static fn() => false);

        return ['exit' => $result->exitCode, 'output' => $buffer];
    }

    /** @param resource $process */
    private static function terminate($process): void
    {
        $status = proc_get_status($process);
        if (!$status['running']) {
            return;
        }
        proc_terminate($process, 15);
        for ($i = 0; $i < 30; $i++) {
            usleep(100_000);
            if (!proc_get_status($process)['running']) {
                return;
            }
        }
        proc_terminate($process, 9);
    }

    /** @return array<string, string> */
    private static function baseEnv(): array
    {
        $env = [];
        foreach (['PATH', 'HOME', 'DOCKER_HOST', 'DOCKER_CONFIG', 'DOCKER_CERT_PATH', 'DOCKER_TLS_VERIFY', 'SYSTEMROOT', 'TEMP', 'TMP', 'LANG'] as $key) {
            $value = getenv($key);
            if ($value !== false) {
                $env[$key] = $value;
            }
        }
        $env['PATH'] ??= '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';

        return $env;
    }
}
