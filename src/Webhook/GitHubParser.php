<?php

declare(strict_types=1);

namespace Vau\Webhook;

/**
 * Interpreta webhooks do GitHub: push (branches e tags), pull_request e ping.
 * Assinatura: cabeçalho X-Hub-Signature-256 com HMAC-SHA256 do corpo bruto.
 */
final class GitHubParser
{
    public static function verify(string $body, string $secret, ?string $header): bool
    {
        if ($header === null || !str_starts_with($header, 'sha256=') || $secret === '') {
            return false;
        }

        return hash_equals('sha256=' . hash_hmac('sha256', $body, $secret), $header);
    }

    /** @param array<string, mixed> $payload */
    public static function parse(string $event, array $payload): WebhookEvent
    {
        return match ($event) {
            'push' => self::push($payload),
            'pull_request' => self::pullRequest($payload),
            'ping' => throw new IgnoredEvent('Ping recebido. O webhook está configurado.'),
            default => throw new IgnoredEvent("Evento {$event} não gera build."),
        };
    }

    /** @param array<string, mixed> $p */
    private static function push(array $p): WebhookEvent
    {
        $ref = (string) ($p['ref'] ?? '');
        if (!empty($p['deleted'])) {
            throw new IgnoredEvent('Referência apagada; nada para construir.');
        }
        $sha = (string) ($p['after'] ?? '');
        if ($ref === '' || preg_match('/^[0-9a-f]{40}$/', $sha) !== 1) {
            throw new \InvalidArgumentException('Push sem ref ou sem commit válido.');
        }
        $head = is_array($p['head_commit'] ?? null) ? $p['head_commit'] : [];
        $isTag = str_starts_with($ref, 'refs/tags/');
        $name = (string) preg_replace('#^refs/(heads|tags)/#', '', $ref);
        $repo = is_array($p['repository'] ?? null) ? $p['repository'] : [];

        return new WebhookEvent(
            kind: $isTag ? 'tag' : 'push',
            event: 'push',
            ref: $ref,
            branch: $isTag ? null : $name,
            tag: $isTag ? $name : null,
            sha: $sha,
            message: isset($head['message']) ? (string) $head['message'] : null,
            author: (string) ($head['author']['username'] ?? $head['author']['name'] ?? $p['pusher']['name'] ?? '') ?: null,
            url: isset($head['url']) ? (string) $head['url'] : (isset($p['compare']) ? (string) $p['compare'] : null),
            cloneUrl: isset($repo['clone_url']) ? (string) $repo['clone_url'] : null,
        );
    }

    /** @param array<string, mixed> $p */
    private static function pullRequest(array $p): WebhookEvent
    {
        $action = (string) ($p['action'] ?? '');
        if (!in_array($action, ['opened', 'synchronize', 'reopened', 'ready_for_review'], true)) {
            throw new IgnoredEvent("Pull request {$action}: só aberturas e novos commits geram build.");
        }
        $pr = is_array($p['pull_request'] ?? null) ? $p['pull_request'] : [];
        $number = (int) ($pr['number'] ?? $p['number'] ?? 0);
        $sha = (string) ($pr['head']['sha'] ?? '');
        if ($number <= 0 || preg_match('/^[0-9a-f]{40}$/', $sha) !== 1) {
            throw new \InvalidArgumentException('Pull request sem número ou sem commit válido.');
        }
        $headRepo = (string) ($pr['head']['repo']['full_name'] ?? '');
        $baseRepo = (string) ($pr['base']['repo']['full_name'] ?? '');

        return new WebhookEvent(
            kind: 'pull_request',
            event: 'pull_request',
            ref: "refs/pull/{$number}/head",
            branch: (string) ($pr['head']['ref'] ?? '') ?: null,
            tag: null,
            sha: $sha,
            message: (string) ($pr['title'] ?? '') ?: null,
            author: (string) ($pr['user']['login'] ?? '') ?: null,
            url: isset($pr['html_url']) ? (string) $pr['html_url'] : null,
            prNumber: $number,
            cloneUrl: (string) ($pr['base']['repo']['clone_url'] ?? '') ?: null,
            isFork: $headRepo !== '' && $baseRepo !== '' && $headRepo !== $baseRepo,
        );
    }
}
