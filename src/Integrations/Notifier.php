<?php

declare(strict_types=1);

namespace Vau\Integrations;

use Vau\Config;
use Vau\Repository\BuildRepository;
use Vau\Support\Logger;
use Vau\Support\Text;

/**
 * Avisa o time num webhook de chat (Slack, Discord, Google Chat, Teams via
 * conector ou qualquer endpoint que aceite JSON). O corpo traz "text"
 * (Slack, Google Chat) e "content" (Discord) com a mesma mensagem.
 */
final class Notifier
{
    public function __construct(private readonly HttpClient $http, private readonly Config $config, private readonly Logger $logger) {}

    /**
     * @param array<string, mixed> $project
     * @param array<string, mixed> $build
     */
    public function notify(array $project, array $build, ?string $previousStatus): void
    {
        $url = (string) ($project['notify_url'] ?? '');
        $mode = (string) ($project['notify_on'] ?? 'falhas');
        if ($url === '' || $mode === 'nunca' || $build['status'] === 'canceled') {
            return;
        }
        $recovered = $build['status'] === 'success' && in_array($previousStatus, ['failed', 'error'], true);
        if ($mode === 'falhas' && $build['status'] === 'success' && !$recovered) {
            return;
        }
        $message = self::message($project, $build, $recovered, $this->config->appUrl());
        try {
            $response = $this->http->request('POST', $url, [], ['text' => $message, 'content' => $message], 8);
            if ($response['status'] < 200 || $response['status'] >= 300) {
                $this->logger->warning('Notificação recusada', ['build' => $build['id'], 'http' => $response['status']]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Falha ao notificar', ['build' => $build['id'], 'exception' => $e]);
        }
    }

    /**
     * @param array<string, mixed> $project
     * @param array<string, mixed> $build
     */
    public static function message(array $project, array $build, bool $recovered, string $appUrl): string
    {
        $label = $recovered ? 'Voltou a passar' : (BuildRepository::STATUS[$build['status']] ?? $build['status']);
        $where = $build['tag'] ? 'tag ' . $build['tag'] : 'branch ' . ($build['branch'] ?? '—');

        return sprintf(
            '%s · %s #%d (%s, %s) em %s — %s/builds/%d',
            $label,
            $project['name'],
            $build['number'],
            $where,
            Text::shortSha($build['commit_sha'] ?? null),
            Text::duration(isset($build['duration_ms']) ? (int) $build['duration_ms'] : null),
            $appUrl,
            $build['id'],
        );
    }
}
