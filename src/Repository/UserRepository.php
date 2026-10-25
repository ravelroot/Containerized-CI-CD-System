<?php

declare(strict_types=1);

namespace Vau\Repository;

use Vau\Database\Connection;
use Vau\Support\Time;

final class UserRepository
{
    public const ROLES = [
        'admin' => 'Administrador',
        'operador' => 'Operador',
        'leitor' => 'Leitor',
    ];

    public function __construct(private readonly Connection $db) {}

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM va_user WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function findByUsername(string $username): ?array
    {
        return $this->db->one('SELECT * FROM va_user WHERE username = ?', [mb_strtolower(trim($username))]);
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->db->all('SELECT id, username, name, role, active, last_login_at, locked_until, created_at FROM va_user ORDER BY name');
    }

    public function count(): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM va_user');
    }

    public function create(string $username, string $name, string $password, string $role): int
    {
        $username = mb_strtolower(trim($username));
        if (preg_match('/^[a-z0-9._-]{3,60}$/', $username) !== 1) {
            throw new \InvalidArgumentException('O usuário deve ter de 3 a 60 caracteres: letras minúsculas, números, ponto, hífen ou sublinhado.');
        }
        if (!isset(self::ROLES[$role])) {
            throw new \InvalidArgumentException('Perfil inválido.');
        }
        self::assertPassword($password);
        if ($this->findByUsername($username) !== null) {
            throw new \InvalidArgumentException('Esse usuário já existe.');
        }
        $now = Time::now();

        return $this->db->insert('va_user', [
            'username' => $username,
            'name' => trim($name) !== '' ? trim($name) : $username,
            'password_hash' => self::hash($password),
            'role' => $role,
            'active' => 1,
            'failed_logins' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function setPassword(int $id, string $password): void
    {
        self::assertPassword($password);
        $this->db->update('va_user', [
            'password_hash' => self::hash($password),
            'failed_logins' => 0,
            'locked_until' => null,
            'updated_at' => Time::now(),
        ], ['id' => $id]);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        $data['updated_at'] = Time::now();
        $this->db->update('va_user', $data, ['id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->db->run('DELETE FROM va_user WHERE id = ?', [$id]);
    }

    public static function hash(string $password): string
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;

        return password_hash($password, $algo);
    }

    public static function assertPassword(string $password): void
    {
        if (mb_strlen($password) < 8) {
            throw new \InvalidArgumentException('A senha precisa ter pelo menos 8 caracteres.');
        }
        if (preg_match('/[A-Za-z]/', $password) !== 1 || preg_match('/[0-9]/', $password) !== 1) {
            throw new \InvalidArgumentException('A senha precisa misturar letras e números.');
        }
    }
}
