<?php

declare(strict_types=1);

namespace Vau\Database;

use Vau\Support\Time;

/**
 * Estrutura do banco descrita uma única vez e traduzida para o dialeto de
 * cada servidor. As migrações são numeradas, idempotentes e registradas em
 * va_schema_migration; rodar migrate duas vezes não altera nada.
 *
 * Tipos lógicos: pk, pkstr:N, fk:tabela:acao, str:N, text, long, int,
 * big, bool, time. Sufixos: "?" aceita nulo, "=valor" define o padrão.
 */
final class Schema
{
    public const PREFIX = 'va_';

    public function __construct(private readonly Connection $db) {}

    /**
     * @return array<string, array{columns: array<string, string>, indexes?: list<array{0: string, 1: list<string>, 2?: bool}>}>
     */
    public static function tables(): array
    {
        return [
            'va_user' => [
                'columns' => [
                    'id' => 'pk',
                    'username' => 'str:60',
                    'name' => 'str:120',
                    'password_hash' => 'str:255',
                    'role' => 'str:20=leitor',
                    'active' => 'bool=1',
                    'failed_logins' => 'int=0',
                    'locked_until' => 'time?',
                    'last_login_at' => 'time?',
                    'created_at' => 'time',
                    'updated_at' => 'time',
                ],
                'indexes' => [['ux_va_user_username', ['username'], true]],
            ],
            'va_api_token' => [
                'columns' => [
                    'id' => 'pk',
                    'user_id' => 'fk:va_user:cascade',
                    'name' => 'str:80',
                    'token_hash' => 'str:64',
                    'token_prefix' => 'str:12',
                    'last_used_at' => 'time?',
                    'expires_at' => 'time?',
                    'created_at' => 'time',
                ],
                'indexes' => [['ux_va_api_token_hash', ['token_hash'], true], ['ix_va_api_token_user', ['user_id']]],
            ],
            'va_session' => [
                'columns' => [
                    'id' => 'pkstr:128',
                    'user_id' => 'fk:va_user:cascade?',
                    'payload' => 'text',
                    'ip' => 'str:45?',
                    'user_agent' => 'str:255?',
                    'last_activity' => 'big',
                ],
                'indexes' => [['ix_va_session_activity', ['last_activity']]],
            ],
            'va_project' => [
                'columns' => [
                    'id' => 'pk',
                    'slug' => 'str:80',
                    'name' => 'str:120',
                    'provider' => 'str:10=github',
                    'repo_url' => 'str:500',
                    'default_branch' => 'str:120=main',
                    'webhook_secret_enc' => 'text',
                    'access_token_enc' => 'text?',
                    'branch_filter' => 'str:500?',
                    'events' => 'str:60=push,pull_request,tag',
                    'pipeline_yaml' => 'text?',
                    'timeout_minutes' => 'int=30',
                    'cpus' => 'str:10=1.0',
                    'memory' => 'str:10=1g',
                    'network' => 'str:20=bridge',
                    'auto_cancel' => 'bool=1',
                    'allow_deploy' => 'bool=1',
                    'report_status' => 'bool=1',
                    'notify_url' => 'str:500?',
                    'notify_on' => 'str:10=falhas',
                    'active' => 'bool=1',
                    'created_by' => 'fk:va_user:null?',
                    'created_at' => 'time',
                    'updated_at' => 'time',
                ],
                'indexes' => [['ux_va_project_slug', ['slug'], true]],
            ],
            'va_secret' => [
                'columns' => [
                    'id' => 'pk',
                    'project_id' => 'fk:va_project:cascade',
                    'name' => 'str:80',
                    'value_enc' => 'text',
                    'deploy_only' => 'bool=0',
                    'created_at' => 'time',
                    'updated_at' => 'time',
                ],
                'indexes' => [['ux_va_secret_name', ['project_id', 'name'], true]],
            ],
            'va_webhook_delivery' => [
                'columns' => [
                    'id' => 'pk',
                    'project_id' => 'fk:va_project:null?',
                    'provider' => 'str:10',
                    'event' => 'str:60',
                    'delivery_uid' => 'str:100',
                    'signature_ok' => 'bool=0',
                    'status_code' => 'int',
                    'outcome' => 'str:255',
                    'headers' => 'text',
                    'payload' => 'long',
                    'payload_size' => 'int=0',
                    'ip' => 'str:45?',
                    'received_at' => 'time',
                ],
                'indexes' => [
                    ['ix_va_delivery_project', ['project_id', 'received_at']],
                    ['ix_va_delivery_uid', ['delivery_uid']],
                    ['ix_va_delivery_received', ['received_at']],
                ],
            ],
            'va_build' => [
                'columns' => [
                    'id' => 'pk',
                    'project_id' => 'fk:va_project:cascade',
                    'number' => 'int',
                    'status' => 'str:12=queued',
                    'trigger_kind' => 'str:16',
                    'event' => 'str:40',
                    'git_ref' => 'str:255',
                    'branch' => 'str:255?',
                    'tag' => 'str:255?',
                    'commit_sha' => 'str:64?',
                    'commit_message' => 'str:500?',
                    'commit_author' => 'str:120?',
                    'commit_url' => 'str:500?',
                    'pr_number' => 'int?',
                    'clone_url' => 'str:500?',
                    'is_fork' => 'bool=0',
                    'is_deploy' => 'bool=0',
                    'delivery_id' => 'fk:va_webhook_delivery:null?',
                    'requested_by' => 'fk:va_user:null?',
                    'retry_of' => 'fk:va_build:null?',
                    'worker_id' => 'str:64?',
                    'pipeline_file' => 'str:60?',
                    'pipeline_json' => 'text?',
                    'queued_at' => 'time',
                    'started_at' => 'time?',
                    'finished_at' => 'time?',
                    'duration_ms' => 'big?',
                    'exit_code' => 'int?',
                    'error_message' => 'str:500?',
                    'cancel_requested' => 'bool=0',
                    'log_bytes' => 'big=0',
                ],
                'indexes' => [
                    ['ux_va_build_number', ['project_id', 'number'], true],
                    ['ix_va_build_status', ['status', 'id']],
                    ['ix_va_build_branch', ['project_id', 'branch', 'status']],
                    ['ix_va_build_queued', ['queued_at']],
                ],
            ],
            'va_build_step' => [
                'columns' => [
                    'id' => 'pk',
                    'build_id' => 'fk:va_build:cascade',
                    'position' => 'int',
                    'name' => 'str:120',
                    'kind' => 'str:10=step',
                    'image' => 'str:255?',
                    'command' => 'text?',
                    'status' => 'str:10=pending',
                    'exit_code' => 'int?',
                    'started_at' => 'time?',
                    'finished_at' => 'time?',
                    'duration_ms' => 'big?',
                    'note' => 'str:255?',
                ],
                'indexes' => [['ux_va_step_position', ['build_id', 'position'], true]],
            ],
            'va_build_log' => [
                'columns' => [
                    'id' => 'pk',
                    'build_id' => 'fk:va_build:cascade',
                    'step_id' => 'fk:va_build_step:cascade?',
                    'stream' => 'str:3=out',
                    'content' => 'long',
                    'created_at' => 'time',
                ],
                'indexes' => [['ix_va_log_build', ['build_id', 'id']]],
            ],
            'va_worker' => [
                'columns' => [
                    'id' => 'pkstr:64',
                    'hostname' => 'str:120',
                    'pid' => 'int',
                    'status' => 'str:10=idle',
                    'current_build_id' => 'fk:va_build:null?',
                    'builds_done' => 'int=0',
                    'started_at' => 'time',
                    'heartbeat_at' => 'time',
                ],
                'indexes' => [['ix_va_worker_heartbeat', ['heartbeat_at']]],
            ],
            'va_audit' => [
                'columns' => [
                    'id' => 'pk',
                    'user_id' => 'fk:va_user:null?',
                    'action' => 'str:60',
                    'target_type' => 'str:30?',
                    'target_id' => 'str:64?',
                    'details' => 'text?',
                    'ip' => 'str:45?',
                    'created_at' => 'time',
                ],
                'indexes' => [['ix_va_audit_created', ['created_at']]],
            ],
            'va_rate_limit' => [
                'columns' => [
                    'bucket' => 'pkstr:120',
                    'hits' => 'int=0',
                    'window_start' => 'big',
                ],
            ],
        ];
    }

    /** @return array<int, list<string>> versão => tabelas criadas nela */
    public static function migrations(): array
    {
        return [
            1 => ['va_user', 'va_api_token', 'va_session', 'va_project', 'va_secret', 'va_webhook_delivery',
                'va_build', 'va_build_step', 'va_build_log', 'va_worker', 'va_audit', 'va_rate_limit'],
        ];
    }

    /** @return list<int> versões aplicadas agora */
    public function migrate(): array
    {
        $this->db->run($this->createTableSql('va_schema_migration', [
            'version' => 'pkint',
            'applied_at' => 'time',
        ]));
        $done = array_map('intval', array_column(
            $this->db->all('SELECT version FROM va_schema_migration'),
            'version',
        ));
        $applied = [];
        foreach (self::migrations() as $version => $tables) {
            if (in_array($version, $done, true)) {
                continue;
            }
            $definitions = self::tables();
            $apply = function () use ($tables, $definitions, $version): void {
                foreach ($tables as $table) {
                    foreach ($this->statementsFor($table, $definitions[$table]) as $sql) {
                        $this->db->run($sql);
                    }
                }
                $this->db->insert('va_schema_migration', ['version' => $version, 'applied_at' => Time::now()], false);
            };
            // MySQL faz commit implícito em DDL; nos outros a migração é atômica
            $this->db->driver === 'mysql' ? $apply() : $this->db->transaction(static fn() => $apply());
            $applied[] = $version;
        }

        return $applied;
    }

    /**
     * @param array{columns: array<string, string>, indexes?: list<array{0: string, 1: list<string>, 2?: bool}>} $definition
     * @return list<string>
     */
    public function statementsFor(string $table, array $definition): array
    {
        $sql = [$this->createTableSql($table, $definition['columns'])];
        foreach ($definition['indexes'] ?? [] as $index) {
            $unique = ($index[2] ?? false) ? 'UNIQUE ' : '';
            $ifNotExists = $this->db->driver === 'mysql' ? '' : 'IF NOT EXISTS ';
            $sql[] = sprintf('CREATE %sINDEX %s%s ON %s (%s)', $unique, $ifNotExists, $index[0], $table, implode(', ', $index[1]));
        }

        return $sql;
    }

    /** @param array<string, string> $columns */
    public function createTableSql(string $table, array $columns): string
    {
        $lines = [];
        $foreign = [];
        foreach ($columns as $name => $spec) {
            [$line, $fk] = $this->column($name, $spec);
            $lines[] = $line;
            if ($fk !== null) {
                $foreign[] = $fk;
            }
        }
        $suffix = $this->db->driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

        return sprintf("CREATE TABLE IF NOT EXISTS %s (\n  %s\n)%s", $table, implode(",\n  ", array_merge($lines, $foreign)), $suffix);
    }

    /** @return array{0: string, 1: string|null} */
    private function column(string $name, string $spec): array
    {
        $default = null;
        if (str_contains($spec, '=')) {
            [$spec, $default] = explode('=', $spec, 2);
        }
        $nullable = str_ends_with($spec, '?');
        $spec = rtrim($spec, '?');
        $parts = explode(':', $spec);
        $kind = $parts[0];
        $driver = $this->db->driver;
        $fk = null;

        $type = match ($kind) {
            'pk' => match ($driver) {
                'mysql' => 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY',
                'pgsql' => 'BIGSERIAL PRIMARY KEY',
                default => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            },
            'pkint' => 'INTEGER PRIMARY KEY',
            'pkstr' => "VARCHAR({$parts[1]}) PRIMARY KEY",
            'fk' => $driver === 'mysql' ? 'BIGINT UNSIGNED' : 'BIGINT',
            'str' => "VARCHAR({$parts[1]})",
            'text' => $driver === 'mysql' ? 'MEDIUMTEXT' : 'TEXT',
            'long' => $driver === 'mysql' ? 'LONGTEXT' : 'TEXT',
            'int' => 'INTEGER',
            'big' => 'BIGINT',
            'bool' => $driver === 'mysql' ? 'TINYINT(1)' : 'SMALLINT',
            'time' => match ($driver) {
                'mysql' => 'DATETIME',
                'pgsql' => 'TIMESTAMP(0)',
                default => 'TEXT',
            },
            default => throw new \LogicException("Tipo desconhecido: {$kind}"),
        };

        if ($kind === 'fk') {
            $action = ($parts[2] ?? 'cascade') === 'null' ? 'SET NULL' : 'CASCADE';
            $fk = sprintf('FOREIGN KEY (%s) REFERENCES %s(id) ON DELETE %s', $name, $parts[1], $action);
        }

        $line = "{$name} {$type}";
        if (!str_starts_with($kind, 'pk')) {
            $line .= $nullable ? ' NULL' : ' NOT NULL';
        }
        if ($default !== null) {
            $line .= ' DEFAULT ' . (is_numeric($default) ? $default : "'" . str_replace("'", "''", $default) . "'");
        }

        return [$line, $fk];
    }

    /** DDL completo de um dialeto, usado pelo comando schema:dump e pelos manuais. */
    public static function dump(string $driver): string
    {
        $pdo = new \PDO('sqlite::memory:');
        $schema = new self(new Connection($pdo, $driver));
        $out = [];
        foreach (self::tables() as $table => $definition) {
            foreach ($schema->statementsFor($table, $definition) as $sql) {
                $out[] = $sql . ';';
            }
        }

        return implode("\n\n", $out) . "\n";
    }
}
