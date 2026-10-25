<?php

declare(strict_types=1);

namespace Vau\Console;

use Vau\App;
use Vau\Database\Schema;
use Vau\Runner\Worker;
use Vau\Support\Crypto;

/**
 * Linha de comando: php bin/vau <comando> [opções]
 */
final class Cli
{
    /** @var array<string, string> */
    public const COMMANDS = [
        'setup' => 'Prepara pastas, chave, banco e o usuário admin (rode uma vez)',
        'serve' => 'Sobe o painel no servidor embutido do PHP [--host=127.0.0.1 --port=8080]',
        'worker' => 'Consome a fila de builds [--once] [--max=N]',
        'migrate' => 'Aplica as migrações pendentes do banco',
        'doctor' => 'Confere PHP, extensões, banco, Docker e permissões',
        'user:create' => 'Cria usuário: user:create <usuario> <perfil> [--nome="Nome"]',
        'user:password' => 'Redefine a senha: user:password <usuario>',
        'token:create' => 'Cria token da API: token:create <usuario> [nome] [--dias=N]',
        'build:run' => 'Enfileira um build: build:run <projeto> [--branch=main] [--commit=SHA]',
        'build:cancel' => 'Cancela um build: build:cancel <id>',
        'prune' => 'Apaga builds e entregas antigos [--dias=VAU_LOG_RETENTION_DAYS]',
        'schema:dump' => 'Imprime o DDL: schema:dump <sqlite|mysql|pgsql>',
        'key:generate' => 'Gera um valor para VAU_APP_KEY',
    ];

    /** @var list<string> */
    private array $args = [];
    /** @var array<string, string|true> */
    private array $options = [];

    public function __construct(private readonly App $app) {}

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        array_shift($argv);
        $command = (string) array_shift($argv);
        foreach ($argv as $arg) {
            if (str_starts_with($arg, '--')) {
                $parts = explode('=', substr($arg, 2), 2);
                $this->options[$parts[0]] = $parts[1] ?? true;
            } else {
                $this->args[] = $arg;
            }
        }

        try {
            return match ($command) {
                'setup' => $this->setup(),
                'serve' => $this->serve(),
                'worker' => $this->worker(),
                'migrate' => $this->migrate(),
                'doctor' => $this->doctor(),
                'user:create' => $this->userCreate(),
                'user:password' => $this->userPassword(),
                'token:create' => $this->tokenCreate(),
                'build:run' => $this->buildRun(),
                'build:cancel' => $this->buildCancel(),
                'prune' => $this->prune(),
                'schema:dump' => $this->schemaDump(),
                'key:generate' => $this->keyGenerate(),
                '', 'help', '--help', '-h' => $this->help(),
                default => $this->fail("Comando desconhecido: {$command}. Veja php bin/vau help."),
            };
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    private function help(): int
    {
        $this->line('Vau ' . App::VERSION . ' · CI/CD em containers');
        $this->line('');
        $this->line('Uso: php bin/vau <comando> [opções]');
        $this->line('');
        foreach (self::COMMANDS as $name => $description) {
            $this->line(sprintf('  %-15s %s', $name, $description));
        }

        return 0;
    }

    private function setup(): int
    {
        $root = $this->app->root;
        foreach (['storage', 'storage/logs', 'storage/workspaces'] as $dir) {
            if (!is_dir("{$root}/{$dir}")) {
                mkdir("{$root}/{$dir}", 0775, true);
            }
        }
        if (!is_file("{$root}/.env") && is_file("{$root}/.env.example")) {
            copy("{$root}/.env.example", "{$root}/.env");
            $this->line('Arquivo .env criado a partir do .env.example.');
        }
        $this->app->crypto();
        $this->migrate();
        if ($this->app->repos()->users()->count() === 0) {
            $password = getenv('VAU_ADMIN_PASSWORD') ?: 'Vau@2026';
            $this->app->repos()->users()->create('admin', 'Administrador', $password, 'admin');
            $this->line('Usuário admin criado. Senha: ' . $password . ' (troque em Sistema e conta).');
        }
        $this->line('Pronto. Suba o painel com php bin/vau serve e o worker com php bin/vau worker.');

        return 0;
    }

    private function serve(): int
    {
        $host = (string) ($this->options['host'] ?? '127.0.0.1');
        $port = (int) ($this->options['port'] ?? 8080);
        $public = $this->app->root . '/public';
        $this->line("Painel em http://{$host}:{$port} (Ctrl+C para parar)");
        putenv('PHP_CLI_SERVER_WORKERS=4');
        passthru(escapeshellarg(PHP_BINARY) . ' -S ' . escapeshellarg("{$host}:{$port}") . ' -t ' . escapeshellarg($public) . ' ' . escapeshellarg($public . '/index.php'), $code);

        return (int) $code;
    }

    private function worker(): int
    {
        $config = $this->app->config;
        $check = $this->app->runtime()->check();
        $this->line(($check['ok'] ? 'Runtime pronto: ' : 'Atenção: ') . $check['detail']);
        $worker = new Worker(
            $this->app->queue(),
            $this->app->runner(),
            $this->app->repos(),
            $this->app->logger(),
            max(1, $config->int('VAU_WORKER_SLEEP', 2)),
            max(30, $config->int('VAU_WORKER_STALE_SECONDS', 120)),
        );
        $max = isset($this->options['max']) ? (int) $this->options['max'] : null;
        $worker->loop(isset($this->options['once']), fn(string $m) => $this->line('[' . date('H:i:s') . '] ' . $m), $max);

        return 0;
    }

    private function migrate(): int
    {
        $applied = (new Schema($this->app->db()))->migrate();
        $this->line($applied === [] ? 'Banco já está na versão mais recente.' : 'Migrações aplicadas: ' . implode(', ', $applied) . '.');

        return 0;
    }

    private function doctor(): int
    {
        $ok = true;
        $check = function (string $label, bool $pass, string $detail = '') use (&$ok): void {
            $ok = $ok && $pass;
            $this->line(sprintf('  %s %-28s %s', $pass ? '✔' : '✖', $label, $detail));
        };
        $this->line('Vau ' . App::VERSION . ' · diagnóstico');
        $check('PHP 8.2+', version_compare(PHP_VERSION, '8.2.0', '>='), PHP_VERSION);
        foreach (['pdo', 'sodium', 'mbstring', 'json', 'curl'] as $ext) {
            $check("extensão {$ext}", extension_loaded($ext));
        }
        $driver = explode(':', $this->app->config->databaseUrl())[0];
        $pdoDriver = match ($driver) {
            'mysql', 'mariadb' => 'pdo_mysql',
            'pgsql', 'postgres', 'postgresql' => 'pdo_pgsql',
            default => 'pdo_sqlite',
        };
        $check("extensão {$pdoDriver}", extension_loaded($pdoDriver));
        $check('pcntl (sinais do worker)', extension_loaded('pcntl'), extension_loaded('pcntl') ? '' : 'opcional; sem ela o worker não faz desligamento gracioso');
        try {
            $this->app->db()->value('SELECT 1');
            $check('banco de dados', true, $this->app->db()->driver);
        } catch (\Throwable $e) {
            $check('banco de dados', false, $e->getMessage());
        }
        $check('storage/ gravável', is_writable($this->app->root . '/storage'));
        $runtime = $this->app->runtime()->check();
        $check('runtime ' . $this->app->config->runtime(), $runtime['ok'], $runtime['detail']);

        return $ok ? 0 : 1;
    }

    private function userCreate(): int
    {
        [$username, $role] = $this->args + ['', 'leitor'];
        if ($username === '') {
            return $this->fail('Uso: user:create <usuario> <admin|operador|leitor> [--nome="Nome"]');
        }
        $password = $this->askPassword();
        $this->app->repos()->users()->create($username, (string) ($this->options['nome'] ?? $username), $password, $role);
        $this->line("Usuário {$username} criado com perfil {$role}.");

        return 0;
    }

    private function userPassword(): int
    {
        $user = $this->app->repos()->users()->findByUsername($this->args[0] ?? '');
        if ($user === null) {
            return $this->fail('Usuário não encontrado.');
        }
        $this->app->repos()->users()->setPassword((int) $user['id'], $this->askPassword());
        $this->line('Senha redefinida e bloqueios removidos.');

        return 0;
    }

    private function tokenCreate(): int
    {
        $user = $this->app->repos()->users()->findByUsername($this->args[0] ?? '');
        if ($user === null) {
            return $this->fail('Usuário não encontrado.');
        }
        $days = isset($this->options['dias']) ? (int) $this->options['dias'] : null;
        $token = $this->app->repos()->tokens()->create((int) $user['id'], $this->args[1] ?? 'cli', $days);
        $this->line($token['token']);

        return 0;
    }

    private function buildRun(): int
    {
        $project = $this->app->repos()->projects()->findBySlug($this->args[0] ?? '');
        if ($project === null) {
            return $this->fail('Projeto não encontrado. Use o identificador (slug) mostrado no painel.');
        }
        $build = $this->app->queue()->enqueueManual(
            $project,
            isset($this->options['branch']) ? (string) $this->options['branch'] : null,
            isset($this->options['commit']) ? (string) $this->options['commit'] : null,
            null,
        );
        $this->line("Build #{$build['number']} (id {$build['id']}) enfileirado: " . $this->app->config->appUrl() . '/builds/' . $build['id']);

        return 0;
    }

    private function buildCancel(): int
    {
        $this->line('Resultado: ' . $this->app->queue()->cancel((int) ($this->args[0] ?? 0)));

        return 0;
    }

    private function prune(): int
    {
        $days = (int) ($this->options['dias'] ?? $this->app->config->int('VAU_LOG_RETENTION_DAYS', 30));
        if ($days < 1) {
            return $this->fail('Informe --dias com pelo menos 1.');
        }
        $builds = $this->app->repos()->builds()->prune($days);
        $deliveries = $this->app->repos()->deliveries()->prune($days);
        $this->line("Removidos {$builds} build(s) e {$deliveries} entrega(s) com mais de {$days} dias.");

        return 0;
    }

    private function schemaDump(): int
    {
        $driver = $this->args[0] ?? 'sqlite';
        if (!in_array($driver, ['sqlite', 'mysql', 'pgsql'], true)) {
            return $this->fail('Use sqlite, mysql ou pgsql.');
        }
        fwrite(STDOUT, Schema::dump($driver));

        return 0;
    }

    private function keyGenerate(): int
    {
        $this->line(Crypto::generateKey());

        return 0;
    }

    private function askPassword(): string
    {
        $env = getenv('VAU_NEW_PASSWORD');
        if (is_string($env) && $env !== '') {
            return $env;
        }
        if (function_exists('posix_isatty') && posix_isatty(STDIN)) {
            fwrite(STDOUT, 'Nova senha: ');
            shell_exec('stty -echo');
            $password = trim((string) fgets(STDIN));
            shell_exec('stty echo');
            fwrite(STDOUT, "\n");

            return $password;
        }
        fwrite(STDOUT, 'Nova senha: ');

        return trim((string) fgets(STDIN));
    }

    private function line(string $text): void
    {
        fwrite(STDOUT, $text . PHP_EOL);
    }

    private function fail(string $message): int
    {
        fwrite(STDERR, 'Erro: ' . $message . PHP_EOL);

        return 1;
    }
}
