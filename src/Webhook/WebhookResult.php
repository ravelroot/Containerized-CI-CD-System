<?php

declare(strict_types=1);

namespace Vau\Webhook;

final class WebhookResult
{
    public ?int $deliveryId = null;
    public ?int $buildId = null;
    public ?int $buildNumber = null;

    public function __construct(public int $status, public string $message) {}

    /** @return array<string, mixed> */
    public function toArray(string $appUrl, string $slug): array
    {
        $out = ['status' => $this->status, 'mensagem' => $this->message, 'entrega' => $this->deliveryId];
        if ($this->buildId !== null) {
            $out['build'] = [
                'id' => $this->buildId,
                'numero' => $this->buildNumber,
                'url' => "{$appUrl}/builds/{$this->buildId}",
                'projeto' => $slug,
            ];
        }

        return $out;
    }
}
