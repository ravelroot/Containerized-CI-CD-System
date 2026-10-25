<?php

declare(strict_types=1);

namespace Vau\Runner;

final class StepResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly int $durationMs,
        public readonly bool $timedOut = false,
        public readonly bool $canceled = false,
    ) {}

    public function ok(): bool
    {
        return $this->exitCode === 0 && !$this->timedOut && !$this->canceled;
    }
}
