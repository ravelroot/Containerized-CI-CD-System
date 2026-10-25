<?php

declare(strict_types=1);

namespace Vau\Runner;

/** Tudo que o runtime precisa saber sobre o build em andamento. */
final class BuildContext
{
    public function __construct(
        public readonly int $buildId,
        public readonly int $number,
        public readonly string $projectSlug,
        public readonly string $repoUrl,
        public readonly ?string $accessToken,
        public readonly string $provider,
        public readonly string $gitRef,
        public readonly ?string $sha,
        public readonly string $cpus,
        public readonly string $memory,
        public readonly string $network,
    ) {}

    /** Nome base de volumes e containers: vau-b123. */
    public function resourceName(): string
    {
        return 'vau-b' . $this->buildId;
    }

    /** URL de clonagem com o token embutido (nunca vai para o log). */
    public function authenticatedUrl(): string
    {
        if ($this->accessToken === null || $this->accessToken === '' || !str_starts_with($this->repoUrl, 'https://')) {
            return $this->repoUrl;
        }
        $user = $this->provider === 'gitlab' ? 'oauth2' : 'x-access-token';

        return 'https://' . rawurlencode($user) . ':' . rawurlencode($this->accessToken) . '@' . substr($this->repoUrl, 8);
    }
}
