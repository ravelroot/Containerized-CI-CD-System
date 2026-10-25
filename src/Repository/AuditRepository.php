<?php

declare(strict_types=1);

namespace Vau\Repository;

use Vau\Database\Connection;
use Vau\Support\Time;

/** Trilha de auditoria: quem fez o quê, quando e de onde. */
final class AuditRepository
{
    public const ACTIONS = [
        'login' => 'Entrou',
        'login.failed' => 'Tentativa de entrada recusada',
        'logout' => 'Saiu',
        'project.create' => 'Criou projeto',
        'project.update' => 'Alterou projeto',
        'project.delete' => 'Excluiu projeto',
        'project.secret_rotate' => 'Trocou o segredo do webhook',
        'secret.put' => 'Gravou segredo',
        'secret.delete' => 'Removeu segredo',
        'build.manual' => 'Executou build manual',
        'build.cancel' => 'Cancelou build',
        'build.retry' => 'Repetiu build',
        'delivery.replay' => 'Reenviou entrega de webhook',
        'user.create' => 'Criou usuário',
        'user.update' => 'Alterou usuário',
        'user.password' => 'Trocou senha',
        'token.create' => 'Criou token de API',
        'token.revoke' => 'Revogou token de API',
    ];

    public function __construct(private readonly Connection $db) {}

    /** @param array<string, mixed> $details */
    public function log(?int $userId, string $action, ?string $targetType = null, int|string|null $targetId = null, array $details = [], ?string $ip = null): void
    {
        $this->db->insert('va_audit', [
            'user_id' => $userId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId === null ? null : (string) $targetId,
            'details' => $details ? (string) json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'ip' => $ip,
            'created_at' => Time::now(),
        ], false);
    }

    /** @return list<array<string, mixed>> */
    public function recent(int $limit = 50): array
    {
        return $this->db->all(
            'SELECT a.*, u.username, u.name AS user_name FROM va_audit a LEFT JOIN va_user u ON u.id = a.user_id ORDER BY a.id DESC LIMIT ?',
            [$limit],
        );
    }
}
