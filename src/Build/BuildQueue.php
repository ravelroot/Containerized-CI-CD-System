<?php

declare(strict_types=1);

namespace Vau\Build;

use Vau\Database\Connection;
use Vau\Database\Repositories;
use Vau\Support\Time;
use Vau\Webhook\WebhookEvent;

/**
 * Fila de builds guardada no próprio banco.
 *
 * A reserva de um build usa uma atualização condicional
 * (UPDATE ... WHERE status = 'queued'): só um worker consegue mudar a
 * linha, em qualquer banco suportado, sem travas longas. Por isso vários
 * workers, em várias máquinas, podem consumir a mesma fila.
 */
final class BuildQueue
{
    public function __construct(private readonly Connection $db, private readonly Repositories $repos) {}

    /**
     * @param array<string, mixed> $project
     * @return array{id: int, number: int, canceled: int}
     */
    public function enqueue(array $project, WebhookEvent $event, string $trigger, ?int $deliveryId = null, ?int $userId = null, ?int $retryOf = null): array
    {
        $projectId = (int) $project['id'];

        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->db->transaction(function (Connection $db) use ($project, $projectId, $event, $trigger, $deliveryId, $userId, $retryOf): array {
                    $canceled = 0;
                    if ((int) $project['auto_cancel'] === 1 && $event->branch !== null && $event->kind !== 'tag') {
                        $canceled = $db->run(
                            "UPDATE va_build SET status = 'canceled', finished_at = ?, error_message = ?
                             WHERE project_id = ? AND branch = ? AND status = 'queued' AND trigger_kind <> 'retry'",
                            [Time::now(), 'Substituído por um commit mais novo na mesma branch.', $projectId, $event->branch],
                        )->rowCount();
                        $db->run(
                            "UPDATE va_build SET cancel_requested = 1 WHERE project_id = ? AND branch = ? AND status = 'running' AND trigger_kind IN ('push', 'pull_request')",
                            [$projectId, $event->branch],
                        );
                    }
                    $number = (int) $db->value('SELECT COALESCE(MAX(number), 0) + 1 FROM va_build WHERE project_id = ?', [$projectId]);
                    $id = $db->insert('va_build', [
                        'project_id' => $projectId,
                        'number' => $number,
                        'status' => 'queued',
                        'trigger_kind' => $trigger,
                        'event' => $event->event,
                        'git_ref' => $event->ref,
                        'branch' => $event->branch,
                        'tag' => $event->tag,
                        'commit_sha' => $event->sha,
                        'commit_message' => $event->message !== null ? mb_substr($event->message, 0, 500) : null,
                        'commit_author' => $event->author !== null ? mb_substr($event->author, 0, 120) : null,
                        'commit_url' => $event->url !== null ? mb_substr($event->url, 0, 500) : null,
                        'pr_number' => $event->prNumber,
                        'clone_url' => $event->cloneUrl !== null ? mb_substr($event->cloneUrl, 0, 500) : null,
                        'is_fork' => (int) $event->isFork,
                        'is_deploy' => 0,
                        'delivery_id' => $deliveryId,
                        'requested_by' => $userId,
                        'retry_of' => $retryOf,
                        'queued_at' => Time::now(),
                        'cancel_requested' => 0,
                        'log_bytes' => 0,
                    ]);

                    return ['id' => $id, 'number' => $number, 'canceled' => $canceled];
                });
            } catch (\PDOException $e) {
                // dois webhooks simultâneos podem disputar o mesmo número; tenta de novo
                if ($attempt >= 5 || !self::isUniqueViolation($e)) {
                    throw $e;
                }
                usleep(random_int(20_000, 120_000));
            }
        }
    }

    /** Build manual, pela tela do projeto, pela API ou pela linha de comando. */
    public function enqueueManual(array $project, ?string $branch, ?string $sha, ?int $userId, string $trigger = 'manual'): array
    {
        $branch = trim((string) $branch) ?: (string) $project['default_branch'];
        if (preg_match('#^[A-Za-z0-9._/-]{1,255}$#', $branch) !== 1 || str_contains($branch, '..')) {
            throw new \InvalidArgumentException('Nome de branch inválido.');
        }
        $sha = trim((string) $sha);
        if ($sha !== '' && preg_match('/^[0-9a-f]{7,40}$/', $sha) !== 1) {
            throw new \InvalidArgumentException('Commit inválido: use o SHA em hexadecimal.');
        }
        $event = new WebhookEvent(
            kind: 'push',
            event: $trigger,
            ref: 'refs/heads/' . $branch,
            branch: $branch,
            tag: null,
            sha: $sha !== '' ? $sha : null,
            message: $trigger === 'api' ? 'Build disparado pela API' : 'Build disparado manualmente',
        );

        return $this->enqueue($project, $event, $trigger, null, $userId);
    }

    /** Novo build com os mesmos parâmetros de um anterior. */
    public function retry(int $buildId, ?int $userId): array
    {
        $build = $this->repos->builds()->find($buildId);
        if ($build === null) {
            throw new \InvalidArgumentException('Build não encontrado.');
        }
        $project = $this->repos->projects()->find((int) $build['project_id']);
        if ($project === null) {
            throw new \InvalidArgumentException('Projeto não encontrado.');
        }
        $event = new WebhookEvent(
            kind: $build['pr_number'] ? 'pull_request' : ($build['tag'] ? 'tag' : 'push'),
            event: (string) $build['event'],
            ref: (string) $build['git_ref'],
            branch: $build['branch'] !== null ? (string) $build['branch'] : null,
            tag: $build['tag'] !== null ? (string) $build['tag'] : null,
            sha: $build['commit_sha'] !== null ? (string) $build['commit_sha'] : null,
            message: $build['commit_message'] !== null ? (string) $build['commit_message'] : null,
            author: $build['commit_author'] !== null ? (string) $build['commit_author'] : null,
            url: $build['commit_url'] !== null ? (string) $build['commit_url'] : null,
            prNumber: $build['pr_number'] !== null ? (int) $build['pr_number'] : null,
            cloneUrl: $build['clone_url'] !== null ? (string) $build['clone_url'] : null,
            isFork: (bool) $build['is_fork'],
        );

        return $this->enqueue($project, $event, 'retry', null, $userId, $buildId);
    }

    /** Reserva o build mais antigo da fila para um worker. */
    public function claim(string $workerId): ?int
    {
        $candidates = $this->db->all("SELECT id FROM va_build WHERE status = 'queued' ORDER BY id LIMIT 10");
        foreach ($candidates as $row) {
            $id = (int) $row['id'];
            $won = $this->db->run(
                "UPDATE va_build SET status = 'running', worker_id = ?, started_at = ? WHERE id = ? AND status = 'queued'",
                [$workerId, Time::now(), $id],
            )->rowCount();
            if ($won === 1) {
                return $id;
            }
        }

        return null;
    }

    /** @return string estado resultante: canceled (estava na fila) ou requested (em execução) */
    public function cancel(int $buildId): string
    {
        $done = $this->db->run(
            "UPDATE va_build SET status = 'canceled', finished_at = ?, error_message = 'Cancelado antes de começar.' WHERE id = ? AND status = 'queued'",
            [Time::now(), $buildId],
        )->rowCount();
        if ($done === 1) {
            return 'canceled';
        }
        $requested = $this->db->run(
            "UPDATE va_build SET cancel_requested = 1 WHERE id = ? AND status = 'running'",
            [$buildId],
        )->rowCount();

        return $requested === 1 ? 'requested' : 'finished';
    }

    /**
     * Builds presos em "running" cujo worker parou de dar sinal de vida
     * (máquina reiniciada, processo morto) são encerrados com erro.
     */
    public function recoverStale(int $staleSeconds): int
    {
        $limit = Time::at(Time::ts() - $staleSeconds);
        $rows = $this->db->all(
            "SELECT b.id FROM va_build b LEFT JOIN va_worker w ON w.id = b.worker_id
             WHERE b.status = 'running' AND (w.id IS NULL OR w.heartbeat_at < ? OR w.status = 'stopped')",
            [$limit],
        );
        $count = 0;
        foreach ($rows as $row) {
            $count += $this->db->run(
                "UPDATE va_build SET status = 'error', finished_at = ?, error_message = ? WHERE id = ? AND status = 'running'",
                [Time::now(), 'O worker parou de responder durante o build.', (int) $row['id']],
            )->rowCount();
            $this->db->run(
                "UPDATE va_build_step SET status = 'canceled' WHERE build_id = ? AND status IN ('pending', 'running')",
                [(int) $row['id']],
            );
        }

        return $count;
    }

    public function queued(): int
    {
        return (int) $this->db->value("SELECT COUNT(*) FROM va_build WHERE status = 'queued'");
    }

    private static function isUniqueViolation(\PDOException $e): bool
    {
        $code = (string) $e->getCode();

        return in_array($code, ['23000', '23505', '19'], true) || str_contains($e->getMessage(), 'UNIQUE');
    }
}
