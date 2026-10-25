<?php

declare(strict_types=1);

namespace Vau\Runner;

/**
 * Onde os builds rodam. O padrão é o DockerRuntime (um container novo por
 * etapa, volume próprio por build). O LocalRuntime existe para
 * desenvolvimento e demonstração, sem isolamento.
 */
interface ContainerRuntime
{
    public function name(): string;

    /** Cria o espaço de trabalho isolado do build (volume Docker ou pasta). */
    public function prepare(BuildContext $ctx): void;

    /**
     * Clona o repositório no espaço de trabalho e faz checkout do commit.
     *
     * @param callable(string $stream, string $chunk): void $onOutput
     * @param callable(): bool $shouldStop
     */
    public function cloneRepository(BuildContext $ctx, callable $onOutput, callable $shouldStop): StepResult;

    /**
     * Lê o primeiro arquivo de pipeline encontrado na raiz do repositório.
     *
     * @return array{file: string, content: string}|null
     */
    public function readPipeline(BuildContext $ctx): ?array;

    /**
     * @param array<string, string> $env
     * @param callable(string $stream, string $chunk): void $onOutput
     * @param callable(): bool $shouldStop
     */
    public function runStep(BuildContext $ctx, int $position, string $image, string $script, array $env, int $timeoutSeconds, callable $onOutput, callable $shouldStop): StepResult;

    /** Remove volume, pasta e containers que tenham sobrado. */
    public function cleanup(BuildContext $ctx): void;

    /**
     * Diagnóstico para a tela Sistema e para o comando doctor.
     *
     * @return array{ok: bool, detail: string}
     */
    public function check(): array;
}
