<?php

declare(strict_types=1);

namespace Vau\Tests;

use Vau\Runner\BuildContext;
use Vau\Runner\ContainerRuntime;
use Vau\Runner\StepResult;

/**
 * Runtime de mentira: devolve o pipeline e os códigos de saída
 * configurados no teste e guarda o que cada etapa recebeu.
 */
final class FakeRuntime implements ContainerRuntime
{
    public ?string $pipeline = "steps:\n  - name: Testes\n    run: make test\n";
    public string $pipelineFile = '.vau.yml';
    public int $cloneExit = 0;
    /** @var array<string, int> nome do script => código de saída */
    public array $exitCodes = [];
    /** @var array<string, string> nome do script => saída impressa */
    public array $outputs = [];
    /** @var list<array{image: string, script: string, env: array<string, string>}> */
    public array $calls = [];
    public bool $cleaned = false;
    public bool $cancelOnStep = false;

    public function name(): string
    {
        return 'falso';
    }

    public function prepare(BuildContext $ctx): void {}

    public function cloneRepository(BuildContext $ctx, callable $onOutput, callable $shouldStop): StepResult
    {
        $onOutput('out', "clonando {$ctx->repoUrl}\n");

        return new StepResult($this->cloneExit, 5);
    }

    public function readPipeline(BuildContext $ctx): ?array
    {
        return $this->pipeline === null ? null : ['file' => $this->pipelineFile, 'content' => $this->pipeline];
    }

    public function runStep(BuildContext $ctx, int $position, string $image, string $script, array $env, int $timeoutSeconds, callable $onOutput, callable $shouldStop): StepResult
    {
        $this->calls[] = ['image' => $image, 'script' => $script, 'env' => $env];
        $onOutput('out', $this->outputs[$script] ?? "rodando {$script}\n");
        if ($this->cancelOnStep && $shouldStop()) {
            return new StepResult(130, 10, false, true);
        }

        return new StepResult($this->exitCodes[$script] ?? 0, 10);
    }

    public function cleanup(BuildContext $ctx): void
    {
        $this->cleaned = true;
    }

    public function check(): array
    {
        return ['ok' => true, 'detail' => 'runtime de teste'];
    }
}
