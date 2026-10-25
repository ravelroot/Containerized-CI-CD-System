<?php

declare(strict_types=1);

namespace Vau\Repository;

use Vau\Database\Connection;
use Vau\Support\Time;

final class WorkerRepository
{
    public function __construct(private readonly Connection $db) {}

    public function register(string $id, string $hostname, int $pid): void
    {
        $now = Time::now();
        $this->db->run('DELETE FROM va_worker WHERE id = ?', [$id]);
        $this->db->insert('va_worker', [
            'id' => $id,
            'hostname' => mb_substr($hostname, 0, 120),
            'pid' => $pid,
            'status' => 'idle',
            'builds_done' => 0,
            'started_at' => $now,
            'heartbeat_at' => $now,
        ], false);
    }

    public function heartbeat(string $id, string $status, ?int $buildId): void
    {
        $this->db->update('va_worker', [
            'status' => $status,
            'current_build_id' => $buildId,
            'heartbeat_at' => Time::now(),
        ], ['id' => $id]);
    }

    public function finished(string $id): void
    {
        $this->db->run('UPDATE va_worker SET builds_done = builds_done + 1 WHERE id = ?', [$id]);
    }

    public function stop(string $id): void
    {
        $this->db->update('va_worker', ['status' => 'stopped', 'current_build_id' => null, 'heartbeat_at' => Time::now()], ['id' => $id]);
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->db->all('SELECT * FROM va_worker ORDER BY started_at DESC');
    }

    /** Workers com batimento nos últimos N segundos. */
    public function alive(int $seconds): int
    {
        return (int) $this->db->value(
            "SELECT COUNT(*) FROM va_worker WHERE status <> 'stopped' AND heartbeat_at >= ?",
            [Time::at(Time::ts() - $seconds)],
        );
    }

    public function pruneStopped(int $hours = 24): void
    {
        $this->db->run(
            "DELETE FROM va_worker WHERE heartbeat_at < ? AND (status = 'stopped' OR current_build_id IS NULL)",
            [Time::at(Time::ts() - $hours * 3600)],
        );
    }
}
