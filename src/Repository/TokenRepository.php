<?php

declare(strict_types=1);

namespace Vau\Repository;

use Vau\Database\Connection;
use Vau\Support\Crypto;
use Vau\Support\Time;

/**
 * Tokens da API. Só o hash SHA-256 fica no banco; o token completo é
 * mostrado uma única vez, no momento da criação.
 */
final class TokenRepository
{
    public function __construct(private readonly Connection $db) {}

    /** @return array{id: int, token: string} */
    public function create(int $userId, string $name, ?int $days = null): array
    {
        $token = 'vau_' . Crypto::token(30);
        $id = $this->db->insert('va_api_token', [
            'user_id' => $userId,
            'name' => mb_substr(trim($name) ?: 'token', 0, 80),
            'token_hash' => hash('sha256', $token),
            'token_prefix' => substr($token, 0, 12),
            'expires_at' => $days ? Time::at(Time::ts() + $days * 86400) : null,
            'created_at' => Time::now(),
        ]);

        return ['id' => $id, 'token' => $token];
    }

    /** @return array<string, mixed>|null usuário dono do token, se válido */
    public function authenticate(string $token): ?array
    {
        $row = $this->db->one(
            'SELECT t.id AS token_id, t.expires_at, u.* FROM va_api_token t JOIN va_user u ON u.id = t.user_id WHERE t.token_hash = ?',
            [hash('sha256', $token)],
        );
        if ($row === null || !(int) $row['active']) {
            return null;
        }
        if ($row['expires_at'] !== null && (Time::toTs((string) $row['expires_at']) ?? 0) < Time::ts()) {
            return null;
        }
        $this->db->update('va_api_token', ['last_used_at' => Time::now()], ['id' => (int) $row['token_id']]);

        return $row;
    }

    /** @return list<array<string, mixed>> */
    public function forUser(int $userId): array
    {
        return $this->db->all(
            'SELECT id, name, token_prefix, last_used_at, expires_at, created_at FROM va_api_token WHERE user_id = ? ORDER BY id DESC',
            [$userId],
        );
    }

    public function revoke(int $id, int $userId): bool
    {
        return $this->db->run('DELETE FROM va_api_token WHERE id = ? AND user_id = ?', [$id, $userId])->rowCount() > 0;
    }
}
