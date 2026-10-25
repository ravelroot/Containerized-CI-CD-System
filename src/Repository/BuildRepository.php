<?php

declare(strict_types=1);

namespace Vau\Repository;

use Vau\Database\Connection;
use Vau\Support\Time;

final class BuildRepository
{
    public const STATUS = [
        'queued' => 'Na fila',
        'running' => 'Executando',
        'success' => 'Sucesso',
        'failed' => 'Falhou',
        'error' => 'Erro',
        'canceled' => 'Cancelado',
    ];

    public const STEP_STATUS = [
        'pending' => 'Aguardando',
        'running' => 'Executando',
        'success' => 'Sucesso',
        'failed' => 'Falhou',
        'skipped' => 'Pulado',
        'canceled' => 'Cancelado',
    ];

    public const TRIGGERS = [
        'push' => 'Push',
        'pull_request' => 'Pull request',
        'tag' => 'Tag',
        'manual' => 'Manual',
        'retry' => 'Repetição',
        'api' => 'API',
    ];

    public const FINISHED = ['success', 'failed', 'error', 'canceled'];

    public function __construct(private readonly Connection $db) {}

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->one(
            'SELECT b.*, p.slug AS project_slug, p.name AS project_name, p.provider, u.name AS requested_by_name
             FROM va_build b JOIN va_project p ON p.id = b.project_id LEFT JOIN va_user u ON u.id = b.requested_by
             WHERE b.id = ?',
            [$id],
        );
    }

    /**
     * @param array{project_id?: int, status?: string, branch?: string} $filters
     * @return list<array<string, mixed>>
     */
    public function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        [$where, $params] = $this->where($filters);
        $params[] = $limit;
        $params[] = $offset;

        return $this->db->all(
            "SELECT b.id, b.project_id, b.number, b.status, b.trigger_kind, b.event, b.branch, b.tag, b.commit_sha,
                    b.commit_message, b.commit_author, b.pr_number, b.is_deploy, b.queued_at, b.started_at,
                    b.finished_at, b.duration_ms, p.slug AS project_slug, p.name AS project_name
             FROM va_build b JOIN va_project p ON p.id = b.project_id {$where}
             ORDER BY b.id DESC LIMIT ? OFFSET ?",
            $params,
        );
    }

    /** @param array{project_id?: int, status?: string, branch?: string} $filters */
    public function count(array $filters = []): int
    {
        [$where, $params] = $this->where($filters);

        return (int) $this->db->value("SELECT COUNT(*) FROM va_build b {$where}", $params);
    }

    /**
     * @param array{project_id?: int, status?: string, branch?: string} $filters
     * @return array{0: string, 1: list<mixed>}
     */
    private function where(array $filters): array
    {
        $conditions = [];
        $params = [];
        if (!empty($filters['project_id'])) {
            $conditions[] = 'b.project_id = ?';
            $params[] = (int) $filters['project_id'];
        }
        if (!empty($filters['status']) && isset(self::STATUS[$filters['status']])) {
            $conditions[] = 'b.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['branch'])) {
            $conditions[] = 'b.branch = ?';
            $params[] = $filters['branch'];
        }

        return [$conditions ? 'WHERE ' . implode(' AND ', $conditions) : '', $params];
    }

    /** @return list<array<string, mixed>> */
    public function steps(int $buildId): array
    {
        return $this->db->all('SELECT * FROM va_build_step WHERE build_id = ? ORDER BY position', [$buildId]);
    }

    public function addStep(int $buildId, int $position, string $name, string $kind, ?string $image, ?string $command, string $status = 'pending', ?string $note = null): int
    {
        return $this->db->insert('va_build_step', [
            'build_id' => $buildId,
            'position' => $position,
            'name' => mb_substr($name, 0, 120),
            'kind' => $kind,
            'image' => $image,
            'command' => $command,
            'status' => $status,
            'note' => $note !== null ? mb_substr($note, 0, 255) : null,
        ]);
    }

    /** @param array<string, mixed> $data */
    public function updateStep(int $stepId, array $data): void
    {
        $this->db->update('va_build_step', $data, ['id' => $stepId]);
    }

    /** @param array<string, mixed> $data */
    public function update(int $buildId, array $data): void
    {
        $this->db->update('va_build', $data, ['id' => $buildId]);
    }

    /**
     * Trechos de log depois do id informado (leitura incremental do painel).
     *
     * @return list<array<string, mixed>>
     */
    public function logsAfter(int $buildId, int $afterId, int $limit = 500): array
    {
        return $this->db->all(
            'SELECT id, step_id, stream, content FROM va_build_log WHERE build_id = ? AND id > ? ORDER BY id LIMIT ?',
            [$buildId, $afterId, $limit],
        );
    }

    public function fullLog(int $buildId): string
    {
        $out = '';
        $after = 0;
        do {
            $rows = $this->logsAfter($buildId, $after, 2000);
            foreach ($rows as $row) {
                $out .= (string) $row['content'];
                $after = (int) $row['id'];
            }
        } while (count($rows) === 2000);

        return $out;
    }

    public function isCancelRequested(int $buildId): bool
    {
        return (bool) $this->db->value('SELECT cancel_requested FROM va_build WHERE id = ?', [$buildId]);
    }

    /** Status do último build terminado antes deste, no mesmo projeto e branch. */
    public function previousFinishedStatus(int $projectId, ?string $branch, int $beforeId): ?string
    {
        $value = $this->db->value(
            'SELECT status FROM va_build WHERE project_id = ? AND branch ' . ($branch === null ? 'IS NULL' : '= ?') . "
             AND id < ? AND status IN ('success', 'failed', 'error') ORDER BY id DESC LIMIT 1",
            $branch === null ? [$projectId, $beforeId] : [$projectId, $branch, $beforeId],
        );

        return $value === null ? null : (string) $value;
    }

    /**
     * Apaga builds terminados antes da data limite (logs e etapas vão junto
     * pelas chaves estrangeiras com ON DELETE CASCADE).
     */
    public function prune(int $days): int
    {
        $limit = Time::at(Time::ts() - $days * 86400);
        $ids = array_map('intval', array_column($this->db->all(
            "SELECT id FROM va_build WHERE queued_at < ? AND status IN ('success', 'failed', 'error', 'canceled')",
            [$limit],
        ), 'id'));
        foreach (array_chunk($ids, 200) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $this->db->run("UPDATE va_build SET retry_of = NULL WHERE retry_of IN ({$marks})", $chunk);
            $this->db->run("DELETE FROM va_build WHERE id IN ({$marks})", $chunk);
        }

        return count($ids);
    }
}
