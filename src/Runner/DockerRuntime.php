<?php

declare(strict_types=1);

namespace Vau\Runner;

use Vau\Config;

/**
 * Cada build ganha um volume Docker próprio (vau-b<ID>) montado em
 * /workspace. O clone roda num container com git; cada etapa roda num
 * container novo, descartado ao fim (--rm), com limites de CPU, memória e
 * processos, sem novos privilégios e só com as capacidades mínimas do
 * kernel. Nada do build toca o disco do servidor nem os outros builds.
 */
final class DockerRuntime implements ContainerRuntime
{
    public const CLONE_SCRIPT = <<<'SH'
set -eu
git config --global --add safe.directory /workspace
git config --global advice.detachedHead false
git init -q /workspace
cd /workspace
git remote add origin "$VAU_CLONE_URL"
echo "Buscando $VAU_REF em $VAU_PUBLIC_URL"
git fetch --quiet --no-tags --depth "$VAU_DEPTH" origin "$VAU_REF"
if [ -n "${VAU_SHA:-}" ]; then
  git checkout --quiet "$VAU_SHA" 2>/dev/null || { git fetch --quiet --no-tags --depth 1 origin "$VAU_SHA" && git checkout --quiet "$VAU_SHA"; }
else
  git checkout --quiet FETCH_HEAD
fi
git remote set-url origin "$VAU_PUBLIC_URL"
git log -1 --format='Commit  %H%nAutor   %an%nData    %ad%n%n    %s'
SH;

    public const READ_PIPELINE_SCRIPT = 'for f in .vau.yml .vau.yaml .vau.json; do if [ -f "$f" ]; then echo "$f"; cat "$f"; exit 0; fi; done; exit 3';

    private string $docker;

    public function __construct(private readonly Config $config)
    {
        $this->docker = $config->get('VAU_DOCKER_BIN', 'docker');
    }

    public function name(): string
    {
        return 'docker';
    }

    public function prepare(BuildContext $ctx): void
    {
        $result = ProcessRunner::capture([$this->docker, 'volume', 'create', '--label', 'vau.build=' . $ctx->buildId, $ctx->resourceName()]);
        if ($result['exit'] !== 0) {
            throw new \RuntimeException('Não foi possível criar o volume do build: ' . trim($result['output']));
        }
    }

    public function cloneRepository(BuildContext $ctx, callable $onOutput, callable $shouldStop): StepResult
    {
        $name = $ctx->resourceName() . '-clone';
        $command = [
            $this->docker, 'run', '--rm', '--name', $name,
            '--label', 'vau.build=' . $ctx->buildId,
            '--network', 'bridge',
            '--memory', '512m', '--pids-limit', '256',
            '--security-opt', 'no-new-privileges',
            '-v', $ctx->resourceName() . ':/workspace',
            '-e', 'VAU_CLONE_URL', '-e', 'VAU_PUBLIC_URL', '-e', 'VAU_REF', '-e', 'VAU_SHA', '-e', 'VAU_DEPTH',
            '-e', 'GIT_TERMINAL_PROMPT=0',
            '--entrypoint', 'sh',
            $this->config->get('VAU_CLONE_IMAGE'),
            '-c', self::CLONE_SCRIPT,
        ];
        $env = [
            'VAU_CLONE_URL' => $ctx->authenticatedUrl(),
            'VAU_PUBLIC_URL' => $ctx->repoUrl,
            'VAU_REF' => $ctx->gitRef,
            'VAU_SHA' => (string) $ctx->sha,
            'VAU_DEPTH' => (string) max(1, $this->config->int('VAU_CLONE_DEPTH', 50)),
        ];

        return ProcessRunner::run($command, $env, null, 600, $onOutput, $shouldStop, fn() => $this->kill($name));
    }

    public function readPipeline(BuildContext $ctx): ?array
    {
        $result = ProcessRunner::capture([
            $this->docker, 'run', '--rm', '--network', 'none',
            '-v', $ctx->resourceName() . ':/workspace:ro', '-w', '/workspace',
            '--entrypoint', 'sh', $this->config->get('VAU_CLONE_IMAGE'),
            '-c', self::READ_PIPELINE_SCRIPT,
        ]);

        return self::splitPipelineOutput($result['exit'], $result['output']);
    }

    public function runStep(BuildContext $ctx, int $position, string $image, string $script, array $env, int $timeoutSeconds, callable $onOutput, callable $shouldStop): StepResult
    {
        $name = $ctx->resourceName() . '-s' . $position;
        $command = [
            $this->docker, 'run', '--rm', '--name', $name,
            '--label', 'vau.build=' . $ctx->buildId,
            '--network', $ctx->network,
            '--cpus', $ctx->cpus,
            '--memory', $ctx->memory, '--memory-swap', $ctx->memory,
            '--pids-limit', (string) $this->config->int('VAU_PIDS_LIMIT', 512),
            '--security-opt', 'no-new-privileges',
        ];
        if ($this->config->bool('VAU_DROP_CAPABILITIES', true)) {
            array_push($command, '--cap-drop', 'ALL');
            foreach (['CHOWN', 'DAC_OVERRIDE', 'FOWNER', 'SETGID', 'SETUID'] as $cap) {
                array_push($command, '--cap-add', $cap);
            }
        }
        array_push($command, '--tmpfs', '/tmp:rw,exec,size=512m', '-v', $ctx->resourceName() . ':/workspace', '-w', '/workspace');
        foreach (array_keys($env) as $key) {
            array_push($command, '-e', $key);
        }
        array_push($command, '--entrypoint', '/bin/sh', $image, '-ec', $script);

        return ProcessRunner::run($command, $env, null, $timeoutSeconds, $onOutput, $shouldStop, fn() => $this->kill($name));
    }

    public function cleanup(BuildContext $ctx): void
    {
        $list = ProcessRunner::capture([$this->docker, 'ps', '-aq', '--filter', 'label=vau.build=' . $ctx->buildId], [], null, 30);
        $ids = array_filter(array_map('trim', explode("\n", $list['output'])));
        if ($ids !== []) {
            ProcessRunner::capture(array_merge([$this->docker, 'rm', '-f'], array_values($ids)), [], null, 60);
        }
        ProcessRunner::capture([$this->docker, 'volume', 'rm', '-f', $ctx->resourceName()], [], null, 60);
    }

    public function check(): array
    {
        try {
            $result = ProcessRunner::capture([$this->docker, 'version', '--format', '{{.Server.Version}}'], [], null, 10);
        } catch (\Throwable $e) {
            return ['ok' => false, 'detail' => 'Docker não encontrado: ' . $e->getMessage()];
        }
        $output = trim($result['output']);
        if ($result['exit'] !== 0) {
            return ['ok' => false, 'detail' => 'Docker não respondeu: ' . (strtok($output, "\n") ?: 'sem resposta')];
        }

        return ['ok' => true, 'detail' => 'Docker Engine ' . $output];
    }

    private function kill(string $container): void
    {
        ProcessRunner::capture([$this->docker, 'kill', $container], [], null, 20);
    }

    /** @return array{file: string, content: string}|null */
    public static function splitPipelineOutput(int $exit, string $output): ?array
    {
        if ($exit !== 0) {
            return null;
        }
        $pos = strpos($output, "\n");
        if ($pos === false) {
            return null;
        }

        return ['file' => trim(substr($output, 0, $pos)), 'content' => substr($output, $pos + 1)];
    }
}
