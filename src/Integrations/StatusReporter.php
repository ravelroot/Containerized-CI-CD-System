<?php

declare(strict_types=1);

namespace Vau\Integrations;

use Vau\App;
use Vau\Config;
use Vau\Repository\ProjectRepository;
use Vau\Support\Logger;

/**
 * Publica o estado do build no commit (o ícone ao lado do commit e o check
 * no pull request) pela API de commit status do GitHub ou do GitLab.
 * Só roda quando o projeto tem token de acesso e a opção ligada.
 */
final class StatusReporter
{
    public function __construct(private readonly HttpClient $http, private readonly Config $config, private readonly Logger $logger) {}

    /**
     * @param array<string, mixed> $project
     * @param array<string, mixed> $build
     */
    public function report(array $project, array $build, string $status): void
    {
        if (!(int) $project['report_status'] || empty($build['commit_sha']) || strlen((string) $build['commit_sha']) !== 40) {
            return;
        }
        try {
            $token = App::get()->repos()->projects()->accessToken($project);
        } catch (\Throwable) {
            $token = null;
        }
        if ($token === null || $token === '') {
            return;
        }
        $path = self::repoPath((string) $project['repo_url']);
        if ($path === null) {
            return;
        }
        $target = $this->config->appUrl() . '/builds/' . $build['id'];
        $description = self::description($status, $build);
        try {
            if ($project['provider'] === 'github') {
                $state = match ($status) {
                    'success' => 'success',
                    'failed' => 'failure',
                    'running', 'queued' => 'pending',
                    default => 'error',
                };
                $host = parse_url((string) $project['repo_url'], PHP_URL_HOST);
                $api = $host === 'github.com' ? 'https://api.github.com' : "https://{$host}/api/v3";
                $response = $this->http->request('POST', "{$api}/repos/{$path}/statuses/{$build['commit_sha']}", [
                    'Authorization' => 'Bearer ' . $token,
                    'X-GitHub-Api-Version' => '2022-11-28',
                ], ['state' => $state, 'target_url' => $target, 'description' => $description, 'context' => 'vau/' . $project['slug']]);
            } elseif ($project['provider'] === 'gitlab') {
                $state = match ($status) {
                    'success' => 'success',
                    'failed', 'error' => 'failed',
                    'canceled' => 'canceled',
                    'queued' => 'pending',
                    default => 'running',
                };
                $host = parse_url((string) $project['repo_url'], PHP_URL_HOST);
                $response = $this->http->request('POST', "https://{$host}/api/v4/projects/" . rawurlencode($path) . "/statuses/{$build['commit_sha']}", [
                    'PRIVATE-TOKEN' => $token,
                ], ['state' => $state, 'target_url' => $target, 'description' => $description, 'name' => 'vau/' . $project['slug']]);
            } else {
                return;
            }
            if ($response['status'] < 200 || $response['status'] >= 300) {
                $this->logger->warning('Provedor recusou o status do commit', ['build' => $build['id'], 'http' => $response['status'], 'error' => $response['error']]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Falha ao publicar status do commit', ['build' => $build['id'], 'exception' => $e]);
        }
    }

    /** "org/repo" a partir de https://host/org/repo(.git) */
    public static function repoPath(string $url): ?string
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $path = (string) preg_replace('/\.git$/', '', $path);

        return substr_count($path, '/') >= 1 ? $path : null;
    }

    /** @param array<string, mixed> $build */
    private static function description(string $status, array $build): string
    {
        return match ($status) {
            'running' => "Build #{$build['number']} em execução",
            'queued' => "Build #{$build['number']} na fila",
            'success' => "Build #{$build['number']} passou",
            'failed' => "Build #{$build['number']} falhou",
            'canceled' => "Build #{$build['number']} cancelado",
            default => "Build #{$build['number']} com erro",
        };
    }

    /** Usado por testes e pela documentação. */
    public static function providers(): array
    {
        return ProjectRepository::PROVIDERS;
    }
}
