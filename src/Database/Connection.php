<?php

declare(strict_types=1);

namespace Vau\Database;

use PDO;
use PDOStatement;

/**
 * Camada fina sobre o PDO que deixa SQLite, MySQL/MariaDB e PostgreSQL
 * com o mesmo comportamento: exceções em erros, linhas como arrays
 * associativos, UTC em todas as sessões e chaves estrangeiras ativas.
 */
final class Connection
{
    private int $depth = 0;

    public function __construct(private readonly PDO $pdo, public readonly string $driver) {}

    /**
     * Aceita "sqlite:caminho", "sqlite::memory:",
     * "mysql://usuario:senha@host:3306/banco" e
     * "pgsql://usuario:senha@host:5432/banco" (ou "postgres://").
     */
    public static function fromUrl(string $url, string $root): self
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];

        if (str_starts_with($url, 'sqlite:')) {
            $path = substr($url, 7);
            if ($path !== ':memory:') {
                if ($path === '' || ($path[0] !== '/' && preg_match('/^[A-Za-z]:[\\\\\/]/', $path) !== 1)) {
                    $path = $root . '/' . ($path === '' ? 'storage/vau.sqlite' : $path);
                }
                if (!is_dir(dirname($path))) {
                    mkdir(dirname($path), 0775, true);
                }
            }
            $pdo = new PDO('sqlite:' . $path, null, null, $options);
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA busy_timeout = 10000');
            if ($path !== ':memory:') {
                $pdo->exec('PRAGMA journal_mode = WAL');
                $pdo->exec('PRAGMA synchronous = NORMAL');
            }

            return new self($pdo, 'sqlite');
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException('VAU_DATABASE_URL inválida. Exemplos: sqlite:storage/vau.sqlite, mysql://u:s@host/banco, pgsql://u:s@host/banco');
        }
        $scheme = strtolower($parts['scheme']);
        $user = isset($parts['user']) ? rawurldecode($parts['user']) : null;
        $pass = isset($parts['pass']) ? rawurldecode($parts['pass']) : null;
        $name = ltrim($parts['path'] ?? '', '/');

        if (in_array($scheme, ['mysql', 'mariadb'], true)) {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $parts['host'], $parts['port'] ?? 3306, $name);
            $options[PDO::ATTR_EMULATE_PREPARES] = false;
            $pdo = new PDO($dsn, $user, $pass, $options);
            $pdo->exec("SET time_zone = '+00:00', sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO'");

            return new self($pdo, 'mysql');
        }

        if (in_array($scheme, ['pgsql', 'postgres', 'postgresql'], true)) {
            $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $parts['host'], $parts['port'] ?? 5432, $name);
            $pdo = new PDO($dsn, $user, $pass, $options);
            $pdo->exec("SET TIME ZONE 'UTC'");

            return new self($pdo, 'pgsql');
        }

        throw new \InvalidArgumentException("Banco não suportado: {$scheme}");
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** @param array<string|int, mixed> $params */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $name = is_int($key) ? $key + 1 : (str_starts_with($key, ':') ? $key : ':' . $key);
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_INT,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $stmt->bindValue($name, is_bool($value) ? (int) $value : $value, $type);
        }
        $stmt->execute();

        return $stmt;
    }

    /**
     * @param array<string|int, mixed> $params
     * @return array<string, mixed>|null
     */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string|int, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /** @param array<string|int, mixed> $params */
    public function value(string $sql, array $params = []): mixed
    {
        $value = $this->run($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    /** @param array<string, mixed> $data */
    public function insert(string $table, array $data, bool $returnId = true): int
    {
        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', array_map(static fn($c) => ':' . $c, $columns)),
        );
        if ($returnId && $this->driver === 'pgsql') {
            return (int) $this->run($sql . ' RETURNING id', $data)->fetchColumn();
        }
        $this->run($sql, $data);

        return $returnId ? (int) $this->pdo->lastInsertId() : 0;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     */
    public function update(string $table, array $data, array $where): int
    {
        $set = [];
        $params = [];
        foreach ($data as $column => $value) {
            $set[] = "{$column} = :s_{$column}";
            $params['s_' . $column] = $value;
        }
        $conditions = [];
        foreach ($where as $column => $value) {
            $conditions[] = "{$column} = :w_{$column}";
            $params['w_' . $column] = $value;
        }
        $sql = sprintf('UPDATE %s SET %s WHERE %s', $table, implode(', ', $set), implode(' AND ', $conditions));

        return $this->run($sql, $params)->rowCount();
    }

    /**
     * Transações aninhadas viram uma só (a mais externa decide o commit).
     *
     * @template T
     * @param callable(self): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        if ($this->depth === 0) {
            if ($this->driver === 'sqlite') {
                $this->pdo->exec('BEGIN IMMEDIATE');
            } else {
                $this->pdo->beginTransaction();
            }
        }
        $this->depth++;
        try {
            $result = $callback($this);
            $this->depth--;
            if ($this->depth === 0) {
                $this->driver === 'sqlite' ? $this->pdo->exec('COMMIT') : $this->pdo->commit();
            }

            return $result;
        } catch (\Throwable $e) {
            $this->depth--;
            if ($this->depth === 0) {
                if ($this->driver === 'sqlite') {
                    $this->pdo->exec('ROLLBACK');
                } elseif ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
            }
            throw $e;
        }
    }

    /** Expressão SQL que converte "Y-m-d H:i:s" em data (para agrupar por dia). */
    public function dayExpression(string $column): string
    {
        return match ($this->driver) {
            'pgsql' => "TO_CHAR({$column}, 'YYYY-MM-DD')",
            'mysql' => "DATE_FORMAT({$column}, '%Y-%m-%d')",
            default => "substr({$column}, 1, 10)",
        };
    }
}
