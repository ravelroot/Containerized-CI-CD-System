<?php

declare(strict_types=1);

namespace Vau\Pipeline;

use Vau\Support\Text;

final class Step
{
    /**
     * @param array<string, string> $env
     * @param list<string> $branches
     */
    public function __construct(
        public readonly string $name,
        public readonly string $run,
        public readonly string $image,
        public readonly array $env = [],
        public readonly array $branches = [],
        public readonly bool $allowFailure = false,
    ) {}

    public function runsOn(?string $branch): bool
    {
        return $this->branches === [] || Text::matchesPatterns($branch, $this->branches);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'image' => $this->image,
            'run' => $this->run,
            'env' => $this->env ?: null,
            'branches' => $this->branches ?: null,
            'allow_failure' => $this->allowFailure ?: null,
        ], static fn($v) => $v !== null);
    }
}
