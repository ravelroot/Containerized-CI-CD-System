<?php

declare(strict_types=1);

namespace Vau\Auth;

use Vau\Config;
use Vau\Database\Repositories;
use Vau\Support\Time;

/**
 * Entrada no painel com bloqueio progressivo por tentativas, perfis de
 * acesso e autenticação da API por token.
 *
 * Perfis: admin (tudo, inclusive usuários), operador (projetos, segredos,
 * builds) e leitor (só consulta).
 */
final class Auth
{
    private const PERMISSIONS = [
        'view' => ['admin', 'operador', 'leitor'],
        'build' => ['admin', 'operador'],
        'manage' => ['admin', 'operador'],
        'admin' => ['admin'],
    ];

    /** @var array<string, mixed>|null */
    private ?array $user = null;

    public function __construct(private readonly Repositories $repos, private readonly Config $config) {}

    /**
     * @return array{ok: bool, message: string, user?: array<string, mixed>}
     */
    public function attempt(string $username, string $password, ?string $ip): array
    {
        $users = $this->repos->users();
        $user = $users->findByUsername($username);
        $generic = 'Usuário ou senha incorretos.';
        if ($user === null) {
            // mesmo custo de uma verificação real, para não revelar quais usuários existem
            password_verify($password, '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');
            $this->repos->audit()->log(null, 'login.failed', 'user', null, ['username' => mb_substr($username, 0, 60)], $ip);

            return ['ok' => false, 'message' => $generic];
        }
        $lockedUntil = Time::toTs($user['locked_until'] ?? null);
        if ($lockedUntil !== null && $lockedUntil > Time::ts()) {
            $minutes = (int) ceil(($lockedUntil - Time::ts()) / 60);

            return ['ok' => false, 'message' => "Muitas tentativas. Tente de novo em {$minutes} min."];
        }
        if (!(int) $user['active']) {
            return ['ok' => false, 'message' => 'Esta conta está desativada.'];
        }
        if (!password_verify($password, (string) $user['password_hash'])) {
            $failed = (int) $user['failed_logins'] + 1;
            $max = max(1, $this->config->int('VAU_LOGIN_MAX_ATTEMPTS', 5));
            $data = ['failed_logins' => $failed];
            if ($failed >= $max) {
                $data['locked_until'] = Time::at(Time::ts() + max(1, $this->config->int('VAU_LOGIN_LOCK_MINUTES', 15)) * 60);
                $data['failed_logins'] = 0;
            }
            $users->update((int) $user['id'], $data);
            $this->repos->audit()->log((int) $user['id'], 'login.failed', 'user', (int) $user['id'], [], $ip);

            return ['ok' => false, 'message' => $failed >= $max ? 'Muitas tentativas. A conta ficou bloqueada por alguns minutos.' : $generic];
        }
        $update = ['failed_logins' => 0, 'locked_until' => null, 'last_login_at' => Time::now()];
        if (password_needs_rehash((string) $user['password_hash'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT)) {
            $update['password_hash'] = \Vau\Repository\UserRepository::hash($password);
        }
        $users->update((int) $user['id'], $update);
        $this->repos->audit()->log((int) $user['id'], 'login', 'user', (int) $user['id'], [], $ip);

        return ['ok' => true, 'message' => 'ok', 'user' => $user];
    }

    /** @param array<string, mixed>|null $user */
    public function setUser(?array $user): void
    {
        $this->user = $user;
    }

    /** @return array<string, mixed>|null */
    public function user(): ?array
    {
        return $this->user;
    }

    public function id(): ?int
    {
        return $this->user !== null ? (int) $this->user['id'] : null;
    }

    public function can(string $permission): bool
    {
        return $this->user !== null && in_array($this->user['role'], self::PERMISSIONS[$permission] ?? [], true);
    }

    /** @param array<string, mixed>|null $user */
    public static function allows(?array $user, string $permission): bool
    {
        return $user !== null && in_array($user['role'], self::PERMISSIONS[$permission] ?? [], true);
    }
}
