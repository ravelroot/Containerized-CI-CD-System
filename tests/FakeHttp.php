<?php

declare(strict_types=1);

namespace Vau\Tests;

use Vau\Integrations\HttpClient;

/** Cliente HTTP que só registra as chamadas (status no commit e notificações). */
final class FakeHttp extends HttpClient
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, json: array<mixed>|null}> */
    public array $requests = [];

    public function request(string $method, string $url, array $headers = [], ?array $json = null, int $timeout = 10): array
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'json' => $json];

        return ['status' => 201, 'body' => '{}', 'error' => null];
    }
}
