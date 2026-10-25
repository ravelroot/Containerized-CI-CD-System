<?php

declare(strict_types=1);

namespace Vau\Http;

final class Request
{
    /** @var array<string, string> */
    public array $attributes = [];

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, string> $headers nomes em minúsculas
     * @param array<string, string> $cookies
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $post,
        public readonly array $headers,
        public readonly array $cookies,
        public readonly string $body,
        public readonly string $ip,
        public readonly bool $secure,
    ) {}

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with((string) $key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr((string) $key, 5)))] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['content-length'] = (string) $_SERVER['CONTENT_LENGTH'];
        }
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $path = '/' . trim(rawurldecode($path), '/');
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $post = $_POST;
        if ($method === 'POST' && isset($post['_method']) && in_array(strtoupper((string) $post['_method']), ['PUT', 'PATCH', 'DELETE'], true)) {
            $method = strtoupper((string) $post['_method']);
        }
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($headers['x-forwarded-proto'] ?? '') === 'https';

        return new self(
            $method,
            $path,
            $_GET,
            $post,
            $headers,
            array_map('strval', $_COOKIE),
            (string) file_get_contents('php://input'),
            (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'),
            $secure,
        );
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    public function str(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->input($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** @return array<mixed>|null */
    public function json(): ?array
    {
        $data = json_decode($this->body, true);

        return is_array($data) ? $data : null;
    }

    public function wantsJson(): bool
    {
        return str_starts_with($this->path, '/api/') || str_contains((string) $this->header('accept'), 'application/json');
    }

    public function bearerToken(): ?string
    {
        $auth = (string) $this->header('authorization');

        return str_starts_with($auth, 'Bearer ') ? trim(substr($auth, 7)) : null;
    }
}
