<?php

declare(strict_types=1);

namespace Vau\Runner;

use Vau\Config;
use Vau\Pipeline\Pipeline;

/**
 * Executa o build direto na máquina, numa pasta temporária, sem containers.
 * Serve para desenvolver o próprio Vau, rodar a demonstração e os testes
 * em ambientes sem Docker. NÃO isola nada: use só com repositórios em que
 * você confia. Ative com VAU_RUNTIME=local.
 */
final class LocalRuntime implements ContainerRuntime
{
    private string $base;

    public function __construct(private readonly Config $config)
    {
        $this->base = $config->path('VAU_WORKSPACE_DIR', 'storage/workspaces');
    }

    public function name(): string
    {
        return 'local';
    }

    private function dir(BuildContext $ctx): string
    {
        return $this->base . '/' . $ctx->resourceName();
    }

    public function prepare(BuildContext $ctx): void
    {
        $dir = $this->dir($ctx);
        if (is_dir($dir)) {
            self::removeTree($dir);
        }
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Não foi possível criar {$dir}.");
        }
    }

    public function cloneRepository(BuildContext $ctx, callable $onOutput, callable $shouldStop): StepResult
    {
        $script = str_replace('/workspace', '"$VAU_WORKSPACE"', DockerRuntime::CLONE_SCRIPT);
        $script = str_replace('git config --global', 'git config --file "$VAU_WORKSPACE/../.gitconfig-' . $ctx->buildId . '"', $script);
        $env = [
            'VAU_WORKSPACE' => $this->dir($ctx),
            'VAU_CLONE_URL' => $ctx->authenticatedUrl(),
            'VAU_PUBLIC_URL' => $ctx->repoUrl,
            'VAU_REF' => $ctx->gitRef,
            'VAU_SHA' => (string) $ctx->sha,
            'VAU_DEPTH' => (string) max(1, $this->config->int('VAU_CLONE_DEPTH', 50)),
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_CONFIG_GLOBAL' => $this->base . '/.gitconfig-' . $ctx->buildId,
        ];

        try {
            return ProcessRunner::run(['sh', '-c', $script], $env, $this->dir($ctx), 600, $onOutput, $shouldStop);
        } finally {
            @unlink($this->base . '/.gitconfig-' . $ctx->buildId);
        }
    }

    public function readPipeline(BuildContext $ctx): ?array
    {
        foreach (Pipeline::FILES as $file) {
            $path = $this->dir($ctx) . '/' . $file;
            if (is_file($path)) {
                return ['file' => $file, 'content' => (string) file_get_contents($path)];
            }
        }

        return null;
    }

    public function runStep(BuildContext $ctx, int $position, string $image, string $script, array $env, int $timeoutSeconds, callable $onOutput, callable $shouldStop): StepResult
    {
        $env['VAU_IMAGE'] = $image;
        $env['HOME'] = $this->dir($ctx);

        return ProcessRunner::run(['sh', '-ec', $script], $env, $this->dir($ctx), $timeoutSeconds, $onOutput, $shouldStop);
    }

    public function cleanup(BuildContext $ctx): void
    {
        $dir = $this->dir($ctx);
        if (is_dir($dir)) {
            self::removeTree($dir);
        }
    }

    public function check(): array
    {
        $git = ProcessRunner::capture(['git', '--version'], [], null, 10);

        return $git['exit'] === 0
            ? ['ok' => true, 'detail' => 'Execução local sem isolamento (' . trim($git['output']) . ')']
            : ['ok' => false, 'detail' => 'git não encontrado no servidor'];
    }

    public static function removeTree(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            @unlink($dir);

            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isDir() && !$item->isLink()) {
                @chmod($item->getPathname(), 0775);
                @rmdir($item->getPathname());
            } else {
                @chmod($item->getPathname(), 0664);
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }
}
