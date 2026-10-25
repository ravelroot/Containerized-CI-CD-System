<?php

declare(strict_types=1);

namespace Vau\Webhook;

use Vau\Build\BuildQueue;
use Vau\Database\Repositories;
use Vau\Support\Logger;
use Vau\Support\Text;

/**
 * Do corpo bruto ao build na fila: identifica o provedor, confere a
 * assinatura, descarta entregas repetidas, aplica os filtros do projeto
 * e registra tudo em va_webhook_delivery.
 */
final class WebhookHandler
{
    public function __construct(
        private readonly Repositories $repos,
        private readonly BuildQueue $queue,
        private readonly Logger $logger,
        private readonly int $maxPayloadBytes,
    ) {}

    /**
     * @param array<string, string> $headers cabeçalhos com nomes em minúsculas
     */
    public function handle(string $slug, array $headers, string $body, ?string $ip, bool $replay = false): WebhookResult
    {
        $provider = isset($headers['x-github-event']) ? 'github' : (isset($headers['x-gitlab-event']) ? 'gitlab' : 'unknown');
        $event = $headers['x-github-event'] ?? $headers['x-gitlab-event'] ?? 'desconhecido';
        $uid = $headers['x-github-delivery'] ?? $headers['x-gitlab-event-uuid'] ?? $headers['x-gitlab-webhook-uuid'] ?? ('sha1-' . sha1($body));
        if ($replay) {
            $uid = 'replay-' . bin2hex(random_bytes(6)) . '-' . mb_substr($uid, 0, 60);
        }

        $project = $this->repos->projects()->findBySlug($slug);
        $projectId = $project !== null ? (int) $project['id'] : null;
        $record = function (bool $signed, WebhookResult $result) use ($projectId, $provider, $event, $uid, $headers, $body, $ip): WebhookResult {
            $result->deliveryId = $this->repos->deliveries()->record($projectId, $provider, $event, $uid, $signed, $result->status, $result->message, $headers, $body, $ip);

            return $result;
        };

        if ($project === null || !(int) $project['active']) {
            return $record(false, new WebhookResult(404, 'Projeto não encontrado ou desativado.'));
        }
        if (strlen($body) > $this->maxPayloadBytes) {
            return $record(false, new WebhookResult(413, 'Corpo maior que o limite configurado.'));
        }
        if ($provider === 'unknown') {
            return $record(false, new WebhookResult(400, 'Provedor não identificado: faltam os cabeçalhos X-GitHub-Event ou X-Gitlab-Event.'));
        }

        $secret = $this->repos->projects()->webhookSecret($project);
        $signed = $provider === 'github'
            ? GitHubParser::verify($body, $secret, $headers['x-hub-signature-256'] ?? null)
            : GitLabParser::verify($secret, $headers['x-gitlab-token'] ?? null);
        if (!$signed) {
            $this->logger->warning('Webhook com assinatura inválida', ['project' => $slug, 'ip' => $ip, 'provider' => $provider]);

            return $record(false, new WebhookResult(401, 'Assinatura inválida.'));
        }

        if (!$replay && $this->repos->deliveries()->wasAccepted((int) $project['id'], $uid)) {
            return $record(true, new WebhookResult(200, 'Entrega repetida; o build já foi criado.'));
        }

        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            // GitHub com content-type application/x-www-form-urlencoded envia payload=...
            parse_str($body, $form);
            $payload = isset($form['payload']) && is_string($form['payload']) ? json_decode($form['payload'], true) : null;
        }
        if (!is_array($payload)) {
            return $record(true, new WebhookResult(400, 'O corpo não é um JSON válido.'));
        }

        try {
            $normalized = $provider === 'github' ? GitHubParser::parse($event, $payload) : GitLabParser::parse($event, $payload);
        } catch (IgnoredEvent $e) {
            return $record(true, new WebhookResult(200, $e->getMessage()));
        } catch (\InvalidArgumentException $e) {
            return $record(true, new WebhookResult(422, $e->getMessage()));
        }

        $allowedEvents = array_filter(explode(',', (string) $project['events']));
        if (!in_array($normalized->kind, $allowedEvents, true)) {
            return $record(true, new WebhookResult(200, "Eventos do tipo {$normalized->kind} estão desligados neste projeto."));
        }
        if (!Text::matchesPatterns($normalized->filterName(), (string) ($project['branch_filter'] ?? ''))) {
            return $record(true, new WebhookResult(200, sprintf('"%s" não passa no filtro de branches do projeto.', $normalized->filterName())));
        }

        $result = $record(true, new WebhookResult(202, 'Build enfileirado.'));
        $build = $this->queue->enqueue($project, $normalized, $normalized->kind, $result->deliveryId);
        $result->buildId = $build['id'];
        $result->buildNumber = $build['number'];
        $message = "Build #{$build['number']} enfileirado" . ($build['canceled'] > 0 ? " ({$build['canceled']} anterior(es) da mesma branch cancelado(s))." : '.');
        $result->message = $message;
        $this->repos->deliveries()->update((int) $result->deliveryId, 202, $message);
        $this->logger->info('Build enfileirado por webhook', ['project' => $slug, 'build' => $build['id'], 'ref' => $normalized->ref]);

        return $result;
    }
}
