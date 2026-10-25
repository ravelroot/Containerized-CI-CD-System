<?php

declare(strict_types=1);

namespace Vau\Http;

use Vau\Database\Connection;

/**
 * Sessão própria guardada no banco (va_session), sem depender de arquivos
 * do PHP: vários servidores do painel atrás de um balanceador enxergam a
 * mesma sessão, e encerrar o acesso de alguém é apagar uma linha.
 * O cookie leva só um identificador aleatório de 256 bits.
 */
final class Session
{
    public const COOKIE = 'vau_sessao';

    /** @var array<string, mixed> */
    private array $data = [];
    private bool $dirty = false;
    private bool $destroyed = false;
    private int $lastActivity = 0;

    private function __construct(private readonly Connection $db, private string $id, private readonly int $lifetime, private bool $isNew) {}

    public static function start(Connection $db, Request $request, int $lifetimeSeconds): self
    {
        $cookie = $request->cookies[self::COOKIE] ?? '';
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $cookie) === 1) {
            $row = $db->one('SELECT payload, last_activity FROM va_session WHERE id = ?', [$cookie]);
            if ($row !== null && (int) $row['last_activity'] >= time() - $lifetimeSeconds) {
                $session = new self($db, $cookie, $lifetimeSeconds, false);
                $data = json_decode((string) $row['payload'], true);
                $session->data = is_array($data) ? $data : [];
                $session->lastActivity = (int) $row['last_activity'];

                return $session;
            }
        }

        return new self($db, self::newId(), $lifetimeSeconds, true);
    }

    private static function newId(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
        $this->dirty = true;
    }

    public function forget(string $key): void
    {
        unset($this->data[$key]);
        $this->dirty = true;
    }

    public function flash(string $type, string $message): void
    {
        $flash = $this->data['_flash'] ?? [];
        $flash[] = ['type' => $type, 'message' => $message];
        $this->set('_flash', $flash);
    }

    /** @return list<array{type: string, message: string}> */
    public function takeFlash(): array
    {
        $flash = $this->data['_flash'] ?? [];
        if ($flash !== []) {
            $this->forget('_flash');
        }

        return $flash;
    }

    public function csrfToken(): string
    {
        $token = $this->data['_csrf'] ?? null;
        if (!is_string($token)) {
            $token = bin2hex(random_bytes(32));
            $this->set('_csrf', $token);
        }

        return $token;
    }

    public function validCsrf(?string $token): bool
    {
        $expected = $this->data['_csrf'] ?? null;

        return is_string($expected) && is_string($token) && hash_equals($expected, $token);
    }

    /** Troca o identificador (depois do login), contra fixação de sessão. */
    public function regenerate(): void
    {
        if (!$this->isNew) {
            $this->db->run('DELETE FROM va_session WHERE id = ?', [$this->id]);
        }
        $this->id = self::newId();
        $this->isNew = true;
        $this->dirty = true;
    }

    public function destroy(): void
    {
        $this->db->run('DELETE FROM va_session WHERE id = ?', [$this->id]);
        $this->data = [];
        $this->destroyed = true;
    }

    public function save(Response $response, bool $secure, ?string $ip, ?string $agent): void
    {
        $cookie = fn(string $value, int $maxAge): string => sprintf(
            '%s=%s; Path=/; Max-Age=%d; HttpOnly; SameSite=Lax%s',
            self::COOKIE,
            $value,
            $maxAge,
            $secure ? '; Secure' : '',
        );
        if ($this->destroyed) {
            $response->withHeader('Set-Cookie', $cookie('', 0));

            return;
        }
        $touch = time() - $this->lastActivity > 60;
        if (!$this->dirty && !$touch) {
            return;
        }
        if ($this->isNew && $this->data === []) {
            return;
        }
        $row = [
            'user_id' => isset($this->data['uid']) ? (int) $this->data['uid'] : null,
            'payload' => (string) json_encode($this->data, JSON_UNESCAPED_UNICODE),
            'ip' => $ip,
            'user_agent' => $agent !== null ? mb_substr($agent, 0, 255) : null,
            'last_activity' => time(),
        ];
        if ($this->isNew) {
            $this->db->insert('va_session', $row + ['id' => $this->id], false);
            $this->isNew = false;
        } else {
            $this->db->update('va_session', $row, ['id' => $this->id]);
        }
        $response->withHeader('Set-Cookie', $cookie($this->id, $this->lifetime));
        if (random_int(1, 100) === 1) {
            $this->db->run('DELETE FROM va_session WHERE last_activity < ?', [time() - $this->lifetime]);
        }
    }
}
