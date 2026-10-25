<?php

declare(strict_types=1);

namespace Vau\Webhook;

/**
 * Interpreta webhooks do GitLab: Push Hook, Tag Push Hook e Merge Request Hook.
 * Autenticação: cabeçalho X-Gitlab-Token igual ao segredo do projeto.
 */
final class GitLabParser
{
    public static function verify(string $secret, ?string $header): bool
    {
        return $header !== null && $secret !== '' && hash_equals($secret, $header);
    }

    /** @param array<string, mixed> $payload */
    public static function parse(string $event, array $payload): WebhookEvent
    {
        return match ($event) {
            'Push Hook', 'Tag Push Hook' => self::push($payload),
            'Merge Request Hook' => self::mergeRequest($payload),
            default => throw new IgnoredEvent("Evento {$event} não gera build."),
        };
    }

    /** @param array<string, mixed> $p */
    private static function push(array $p): WebhookEvent
    {
        $ref = (string) ($p['ref'] ?? '');
        $sha = (string) ($p['checkout_sha'] ?? $p['after'] ?? '');
        if ($sha === '' || preg_match('/^0+$/', (string) ($p['after'] ?? '1')) === 1) {
            throw new IgnoredEvent('Referência apagada; nada para construir.');
        }
        if ($ref === '' || preg_match('/^[0-9a-f]{40}$/', $sha) !== 1) {
            throw new \InvalidArgumentException('Push sem ref ou sem commit válido.');
        }
        $isTag = str_starts_with($ref, 'refs/tags/');
        $name = (string) preg_replace('#^refs/(heads|tags)/#', '', $ref);
        $commit = null;
        foreach ((array) ($p['commits'] ?? []) as $c) {
            if (is_array($c) && ($c['id'] ?? '') === $sha) {
                $commit = $c;
            }
        }
        $project = is_array($p['project'] ?? null) ? $p['project'] : [];

        return new WebhookEvent(
            kind: $isTag ? 'tag' : 'push',
            event: $isTag ? 'tag_push' : 'push',
            ref: $ref,
            branch: $isTag ? null : $name,
            tag: $isTag ? $name : null,
            sha: $sha,
            message: isset($commit['message']) ? (string) $commit['message'] : (isset($p['message']) ? (string) $p['message'] : null),
            author: (string) ($commit['author']['name'] ?? $p['user_username'] ?? $p['user_name'] ?? '') ?: null,
            url: isset($commit['url']) ? (string) $commit['url'] : null,
            cloneUrl: (string) ($project['git_http_url'] ?? $p['repository']['git_http_url'] ?? '') ?: null,
        );
    }

    /** @param array<string, mixed> $p */
    private static function mergeRequest(array $p): WebhookEvent
    {
        $attr = is_array($p['object_attributes'] ?? null) ? $p['object_attributes'] : [];
        $action = (string) ($attr['action'] ?? '');
        if (!in_array($action, ['open', 'reopen', 'update'], true)) {
            throw new IgnoredEvent("Merge request {$action}: só aberturas e novos commits geram build.");
        }
        if ($action === 'update' && !isset($attr['oldrev'])) {
            throw new IgnoredEvent('Merge request alterado sem novos commits.');
        }
        $iid = (int) ($attr['iid'] ?? 0);
        $sha = (string) ($attr['last_commit']['id'] ?? '');
        if ($iid <= 0 || preg_match('/^[0-9a-f]{40}$/', $sha) !== 1) {
            throw new \InvalidArgumentException('Merge request sem número ou sem commit válido.');
        }
        $source = (int) ($attr['source_project_id'] ?? 0);
        $target = (int) ($attr['target_project_id'] ?? 0);

        return new WebhookEvent(
            kind: 'pull_request',
            event: 'merge_request',
            ref: "refs/merge-requests/{$iid}/head",
            branch: (string) ($attr['source_branch'] ?? '') ?: null,
            tag: null,
            sha: $sha,
            message: (string) ($attr['title'] ?? '') ?: null,
            author: (string) ($p['user']['username'] ?? $attr['last_commit']['author']['name'] ?? '') ?: null,
            url: isset($attr['url']) ? (string) $attr['url'] : null,
            prNumber: $iid,
            cloneUrl: (string) ($attr['target']['git_http_url'] ?? $p['project']['git_http_url'] ?? '') ?: null,
            isFork: $source !== 0 && $target !== 0 && $source !== $target,
        );
    }
}
