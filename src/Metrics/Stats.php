<?php

declare(strict_types=1);

namespace Vau\Metrics;

use Vau\App;
use Vau\Database\Connection;
use Vau\Support\Time;

/** Números do painel e da rota /metrics (formato Prometheus). */
final class Stats
{
    public function __construct(private readonly Connection $db) {}

    /** @return array{total: int, success: int, failed: int, rate: ?int, avg_ms: ?int, p95_ms: ?int, queued: int, running: int} */
    public function summary(int $days = 7): array
    {
        $since = Time::at(Time::ts() - $days * 86400);
        $row = $this->db->one(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) AS ok,
                    SUM(CASE WHEN status IN ('failed', 'error') THEN 1 ELSE 0 END) AS bad,
                    AVG(CASE WHEN status IN ('success', 'failed') THEN duration_ms END) AS avg_ms
             FROM va_build WHERE queued_at >= ?",
            [$since],
        ) ?? [];
        $durations = array_map('intval', array_column($this->db->all(
            "SELECT duration_ms FROM va_build WHERE queued_at >= ? AND status IN ('success', 'failed') AND duration_ms IS NOT NULL ORDER BY duration_ms",
            [$since],
        ), 'duration_ms'));
        $p95 = $durations === [] ? null : $durations[(int) max(0, ceil(count($durations) * 0.95) - 1)];
        $ok = (int) ($row['ok'] ?? 0);
        $bad = (int) ($row['bad'] ?? 0);

        return [
            'total' => (int) ($row['total'] ?? 0),
            'success' => $ok,
            'failed' => $bad,
            'rate' => ($ok + $bad) > 0 ? (int) round(100 * $ok / ($ok + $bad)) : null,
            'avg_ms' => isset($row['avg_ms']) ? (int) round((float) $row['avg_ms']) : null,
            'p95_ms' => $p95,
            'queued' => (int) $this->db->value("SELECT COUNT(*) FROM va_build WHERE status = 'queued'"),
            'running' => (int) $this->db->value("SELECT COUNT(*) FROM va_build WHERE status = 'running'"),
        ];
    }

    /**
     * Builds por dia, separados em sucesso e falha, no fuso local.
     *
     * @return list<array{day: string, label: string, success: int, failed: int, other: int}>
     */
    public function daily(int $days, \DateTimeZone $tz): array
    {
        $since = Time::at(Time::ts() - ($days + 1) * 86400);
        $rows = $this->db->all('SELECT queued_at, status FROM va_build WHERE queued_at >= ?', [$since]);
        $series = [];
        $today = (new \DateTimeImmutable('@' . Time::ts()))->setTimezone($tz);
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = $today->modify("-{$i} days");
            $series[$d->format('Y-m-d')] = ['day' => $d->format('Y-m-d'), 'label' => $d->format('d/m'), 'success' => 0, 'failed' => 0, 'other' => 0];
        }
        foreach ($rows as $row) {
            $ts = Time::toTs((string) $row['queued_at']);
            if ($ts === null) {
                continue;
            }
            $key = (new \DateTimeImmutable('@' . $ts))->setTimezone($tz)->format('Y-m-d');
            if (!isset($series[$key])) {
                continue;
            }
            $bucket = match ($row['status']) {
                'success' => 'success',
                'failed', 'error' => 'failed',
                default => 'other',
            };
            $series[$key][$bucket]++;
        }

        return array_values($series);
    }

    /** Texto no formato de exposição do Prometheus. */
    public function prometheus(): string
    {
        $lines = [];
        $lines[] = '# HELP vau_info Versão do Vau.';
        $lines[] = '# TYPE vau_info gauge';
        $lines[] = 'vau_info{version="' . App::VERSION . '"} 1';
        $lines[] = '# HELP vau_builds Builds por projeto e status.';
        $lines[] = '# TYPE vau_builds gauge';
        foreach ($this->db->all('SELECT p.slug, b.status, COUNT(*) AS n FROM va_build b JOIN va_project p ON p.id = b.project_id GROUP BY p.slug, b.status ORDER BY p.slug, b.status') as $row) {
            $lines[] = sprintf('vau_builds{project="%s",status="%s"} %d', addcslashes((string) $row['slug'], '"\\'), $row['status'], $row['n']);
        }
        $lines[] = '# HELP vau_queue_depth Builds aguardando na fila.';
        $lines[] = '# TYPE vau_queue_depth gauge';
        $lines[] = 'vau_queue_depth ' . (int) $this->db->value("SELECT COUNT(*) FROM va_build WHERE status = 'queued'");
        $lines[] = '# HELP vau_workers_alive Workers com batimento nos últimos 2 minutos.';
        $lines[] = '# TYPE vau_workers_alive gauge';
        $lines[] = 'vau_workers_alive ' . (int) $this->db->value(
            "SELECT COUNT(*) FROM va_worker WHERE status <> 'stopped' AND heartbeat_at >= ?",
            [Time::at(Time::ts() - 120)],
        );
        $lines[] = '# HELP vau_build_duration_seconds_avg Duração média dos builds terminados nas últimas 24 h.';
        $lines[] = '# TYPE vau_build_duration_seconds_avg gauge';
        $avg = $this->db->value(
            "SELECT AVG(duration_ms) FROM va_build WHERE status IN ('success', 'failed') AND finished_at >= ?",
            [Time::at(Time::ts() - 86400)],
        );
        $lines[] = 'vau_build_duration_seconds_avg ' . round(((float) $avg) / 1000, 3);
        $lines[] = '# HELP vau_webhook_deliveries_total Entregas de webhook por código de resposta (últimas 24 h).';
        $lines[] = '# TYPE vau_webhook_deliveries_total gauge';
        foreach ($this->db->all('SELECT status_code, COUNT(*) AS n FROM va_webhook_delivery WHERE received_at >= ? GROUP BY status_code ORDER BY status_code', [Time::at(Time::ts() - 86400)]) as $row) {
            $lines[] = sprintf('vau_webhook_deliveries_total{code="%d"} %d', $row['status_code'], $row['n']);
        }

        return implode("\n", $lines) . "\n";
    }
}
