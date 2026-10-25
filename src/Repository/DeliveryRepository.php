<?php

declare(strict_types=1);

namespace Vau\Repository;

use Vau\Database\Connection;
use Vau\Support\Time;

/** Registro de toda chamada recebida em /webhook, aceita ou recusada. */
final class DeliveryRepository
{
    private const MAX_PAYLOAD = 262144;

    public function __construct(private readonly Connection $db) {}

    /**
     * @param array<string, string> $headers
     */
    public function record(?int $projectId, string $provider, string $event, string $uid, bool $signatureOk, int $status, string $outcome, array $headers, string $payload, ?string $ip): int
    {
        $safeHeaders = [];
        foreach ($headers as $name => $value) {
            $lower = strtolower($name);
            if (in_array($lower, ['x-gitlab-token', 'authorization', 'cookie'], true)) {
                $value = '•••';
            }
            $safeHeaders[$name] = mb_substr($value, 0, 300);
        }

        return $this->db->insert('va_webhook_delivery', [
            'project_id' => $projectId,
            'provider' => $provider,
            'event' => mb_substr($event, 0, 60),
            'delivery_uid' => mb_substr($uid, 0, 100),
            'signature_ok' => (int) $signatureOk,
            'status_code' => $status,
            'outcome' => mb_substr($outcome, 0, 255),
            'headers' => (string) json_encode($safeHeaders, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'payload' => strlen($payload) > self::MAX_PAYLOAD ? substr($payload, 0, self::MAX_PAYLOAD) : $payload,
            'payload_size' => strlen($payload),
            'ip' => $ip,
            'received_at' => Time::now(),
        ]);
    }

    public function update(int $id, int $status, string $outcome): void
    {
        $this->db->update('va_webhook_delivery', ['status_code' => $status, 'outcome' => mb_substr($outcome, 0, 255)], ['id' => $id]);
    }

    /** Uma entrega com o mesmo identificador já foi aceita para o projeto? */
    public function wasAccepted(int $projectId, string $uid): bool
    {
        return $this->db->value(
            'SELECT id FROM va_webhook_delivery WHERE project_id = ? AND delivery_uid = ? AND status_code = 202 LIMIT 1',
            [$projectId, $uid],
        ) !== null;
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->one(
            'SELECT d.*, p.slug AS project_slug, p.name AS project_name FROM va_webhook_delivery d
             LEFT JOIN va_project p ON p.id = d.project_id WHERE d.id = ?',
            [$id],
        );
    }

    /** @return list<array<string, mixed>> */
    public function recent(?int $projectId = null, int $limit = 50): array
    {
        $where = $projectId ? 'WHERE d.project_id = ?' : '';
        $params = $projectId ? [$projectId, $limit] : [$limit];

        return $this->db->all(
            "SELECT d.id, d.project_id, d.provider, d.event, d.delivery_uid, d.signature_ok, d.status_code, d.outcome,
                    d.payload_size, d.ip, d.received_at, p.slug AS project_slug, p.name AS project_name,
                    (SELECT MIN(b.id) FROM va_build b WHERE b.delivery_id = d.id) AS build_id
             FROM va_webhook_delivery d LEFT JOIN va_project p ON p.id = d.project_id {$where}
             ORDER BY d.id DESC LIMIT ?",
            $params,
        );
    }

    public function prune(int $days): int
    {
        return $this->db->run(
            'DELETE FROM va_webhook_delivery WHERE received_at < ? AND id NOT IN (SELECT delivery_id FROM va_build WHERE delivery_id IS NOT NULL)',
            [Time::at(Time::ts() - $days * 86400)],
        )->rowCount();
    }
}
