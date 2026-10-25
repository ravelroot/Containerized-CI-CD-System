<?php

declare(strict_types=1);

namespace Vau\Webhook;

/**
 * Evento já normalizado, igual para GitHub, GitLab e disparos manuais.
 * O kind é "push", "pull_request" ou "tag".
 */
final class WebhookEvent
{
    public function __construct(
        public readonly string $kind,
        public readonly string $event,
        public readonly string $ref,
        public readonly ?string $branch,
        public readonly ?string $tag,
        public readonly ?string $sha,
        public readonly ?string $message = null,
        public readonly ?string $author = null,
        public readonly ?string $url = null,
        public readonly ?int $prNumber = null,
        public readonly ?string $cloneUrl = null,
        public readonly bool $isFork = false,
    ) {}

    /** Nome usado nos filtros de branch: a branch, a tag ou a branch de origem do PR. */
    public function filterName(): ?string
    {
        return $this->branch ?? $this->tag;
    }
}
