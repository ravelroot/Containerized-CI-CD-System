<?php

declare(strict_types=1);

namespace Vau;

/**
 * Configuração lida do ambiente e do arquivo .env.
 *
 * Variáveis do ambiente real têm prioridade sobre o .env, o que permite
 * sobrescrever qualquer valor no Docker ou no CI sem editar arquivos.
 */
final class Config
{
    /** Valores padrão documentados no manual e no .env.example. */
    public const DEFAULTS = [
        'VAU_APP_URL' => 'http://127.0.0.1:8080',
        'VAU_TIMEZONE' => 'America/Sao_Paulo',
        'VAU_DATABASE_URL' => 'sqlite:storage/vau.sqlite',
        'VAU_RUNTIME' => 'docker',
        'VAU_DOCKER_BIN' => 'docker',
        'VAU_CLONE_IMAGE' => 'alpine/git:latest',
        'VAU_DEFAULT_IMAGE' => 'alpine:3.20',
        'VAU_BUILD_TIMEOUT' => '30',
        'VAU_CPUS' => '1.0',
        'VAU_MEMORY' => '1g',
        'VAU_PIDS_LIMIT' => '512',
        'VAU_NETWORK' => 'bridge',
        'VAU_DROP_CAPABILITIES' => 'true',
        'VAU_CLONE_DEPTH' => '50',
        'VAU_WORKSPACE_DIR' => 'storage/workspaces',
        'VAU_MAX_LOG_MB' => '10',
        'VAU_LOG_RETENTION_DAYS' => '30',
        'VAU_MAX_PAYLOAD_KB' => '2048',
        'VAU_WEBHOOK_RATE_LIMIT' => '120',
        'VAU_LOGIN_MAX_ATTEMPTS' => '5',
        'VAU_LOGIN_LOCK_MINUTES' => '15',
        'VAU_SESSION_MINUTES' => '480',
        'VAU_WORKER_SLEEP' => '2',
        'VAU_WORKER_STALE_SECONDS' => '120',
        'VAU_LOG_LEVEL' => 'info',
    ];

    /** @param array<string, string> $values */
    private function __construct(private array $values, private readonly string $root) {}

    public static function load(string $root): self
    {
        $values = self::DEFAULTS;
        $file = $root . '/.env';
        if (is_file($file)) {
            $values = array_merge($values, self::parseEnv((string) file_get_contents($file)));
        }
        foreach (array_keys($values) as $key) {
            $env = getenv($key);
            if ($env !== false && $env !== '') {
                $values[$key] = $env;
            }
        }
        foreach (getenv() as $key => $env) {
            if (str_starts_with((string) $key, 'VAU_') && !isset($values[$key])) {
                $values[(string) $key] = (string) $env;
            }
        }

        return new self($values, $root);
    }

    /** @param array<string, string> $values */
    public static function fromArray(array $values, string $root): self
    {
        return new self(array_merge(self::DEFAULTS, $values), $root);
    }

    /** @return array<string, string> */
    public static function parseEnv(string $content): array
    {
        $out = [];
        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if (str_starts_with($key, 'export ')) {
                $key = trim(substr($key, 7));
            }
            if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
                $quote = $value[0];
                $end = strrpos($value, $quote);
                $value = $end > 0 ? substr($value, 1, $end - 1) : substr($value, 1);
                if ($quote === '"') {
                    $value = str_replace(['\\n', '\\"'], ["\n", '"'], $value);
                }
            } else {
                $value = trim((string) preg_replace('/\s+#.*$/', '', $value));
            }
            $out[$key] = $value;
        }

        return $out;
    }

    public function get(string $key, ?string $default = null): string
    {
        $value = $this->values[$key] ?? null;

        return ($value === null || $value === '') ? (string) $default : $value;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->values[$key] ?? '';

        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = strtolower((string) ($this->values[$key] ?? ''));
        if ($value === '') {
            return $default;
        }

        return in_array($value, ['1', 'true', 'yes', 'on', 'sim'], true);
    }

    /** Resolve caminhos relativos a partir da raiz do projeto. */
    public function path(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);
        if ($value === '') {
            return $value;
        }
        if ($value[0] === '/' || preg_match('/^[A-Za-z]:[\\\\\/]/', $value) === 1) {
            return $value;
        }

        return $this->root . '/' . $value;
    }

    public function root(): string
    {
        return $this->root;
    }

    public function databaseUrl(): string
    {
        return $this->get('VAU_DATABASE_URL');
    }

    public function runtime(): string
    {
        $runtime = strtolower($this->get('VAU_RUNTIME', 'docker'));

        return in_array($runtime, ['docker', 'local'], true) ? $runtime : 'docker';
    }

    public function appUrl(): string
    {
        return rtrim($this->get('VAU_APP_URL'), '/');
    }

    public function timezone(): \DateTimeZone
    {
        try {
            return new \DateTimeZone($this->get('VAU_TIMEZONE', 'America/Sao_Paulo'));
        } catch (\Exception) {
            return new \DateTimeZone('UTC');
        }
    }

    /**
     * Configuração ativa sem segredos, para a tela Sistema e para o doctor.
     *
     * @return array<string, string>
     */
    public function publicSummary(): array
    {
        $hidden = ['VAU_APP_KEY'];
        $out = [];
        foreach ($this->values as $key => $value) {
            if (!str_starts_with($key, 'VAU_') || in_array($key, $hidden, true)) {
                continue;
            }
            if ($key === 'VAU_DATABASE_URL') {
                $value = (string) preg_replace('#//([^:/@]+):[^@]*@#', '//$1:•••@', $value);
            }
            $out[$key] = $value;
        }
        ksort($out);

        return $out;
    }
}
