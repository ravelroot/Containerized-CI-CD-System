<?php

declare(strict_types=1);

namespace Vau\Http\Controller;

use Vau\Http\Request;
use Vau\Http\Response;
use Vau\Metrics\Stats;
use Vau\Repository\BuildRepository;

/**
 * API REST v1 com token (Authorization: Bearer vau_...). Respostas em JSON,
 * campos em inglês, datas em UTC no formato ISO 8601.
 */
final class ApiController extends Controller
{
    /** @param array<string, string> $params */
    public function projects(Request $request, array $params): Response
    {
        return Response::json(['data' => array_map(fn($p) => $this->projectJson($p), $this->repos->projects()->allWithStats())]);
    }

    /** @param array<string, string> $params */
    public function project(Request $request, array $params): Response
    {
        $project = $this->projectOr404($params['slug']);

        return Response::json(['data' => $this->projectJson($project)]);
    }

    /** @param array<string, string> $params */
    public function builds(Request $request, array $params): Response
    {
        $project = $this->projectOr404($params['slug']);
        $limit = min(100, max(1, $request->int('limit', 20)));
        $filters = ['project_id' => (int) $project['id']];
        if (isset(BuildRepository::STATUS[$request->str('status')])) {
            $filters['status'] = $request->str('status');
        }
        if ($request->str('branch') !== '') {
            $filters['branch'] = $request->str('branch');
        }

        return Response::json([
            'data' => array_map(fn($b) => $this->buildJson($b), $this->repos->builds()->list($filters, $limit, max(0, $request->int('offset', 0)))),
            'total' => $this->repos->builds()->count($filters),
        ]);
    }

    /** @param array<string, string> $params */
    public function trigger(Request $request, array $params): Response
    {
        $project = $this->projectOr404($params['slug']);
        $body = $request->json() ?? [];
        try {
            $build = $this->app->queue()->enqueueManual(
                $project,
                isset($body['branch']) ? (string) $body['branch'] : $request->str('branch'),
                isset($body['commit']) ? (string) $body['commit'] : $request->str('commit'),
                $this->ctx->auth->id(),
                'api',
            );
        } catch (\InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
        $this->audit('build.manual', 'build', $build['id'], ['via' => 'api']);

        return Response::json(['data' => $this->buildJson($this->buildOr404($build['id']))], 201)
            ->withHeader('Location', '/api/v1/builds/' . $build['id']);
    }

    /** @param array<string, string> $params */
    public function build(Request $request, array $params): Response
    {
        $build = $this->buildOr404((int) $params['id']);
        $data = $this->buildJson($build);
        $data['steps'] = array_map(static fn($s) => [
            'position' => (int) $s['position'],
            'name' => $s['name'],
            'kind' => $s['kind'],
            'image' => $s['image'],
            'status' => $s['status'],
            'exit_code' => $s['exit_code'] !== null ? (int) $s['exit_code'] : null,
            'duration_ms' => $s['duration_ms'] !== null ? (int) $s['duration_ms'] : null,
            'note' => $s['note'],
        ], $this->repos->builds()->steps((int) $build['id']));

        return Response::json(['data' => $data]);
    }

    /** @param array<string, string> $params */
    public function log(Request $request, array $params): Response
    {
        $build = $this->buildOr404((int) $params['id']);

        return Response::text($this->repos->builds()->fullLog((int) $build['id']));
    }

    /** @param array<string, string> $params */
    public function cancel(Request $request, array $params): Response
    {
        $build = $this->buildOr404((int) $params['id']);
        $result = $this->app->queue()->cancel((int) $build['id']);
        $this->audit('build.cancel', 'build', (int) $build['id'], ['via' => 'api']);

        return Response::json(['result' => $result], $result === 'finished' ? 409 : 202);
    }

    /** @param array<string, string> $params */
    public function retry(Request $request, array $params): Response
    {
        $build = $this->buildOr404((int) $params['id']);
        $new = $this->app->queue()->retry((int) $build['id'], $this->ctx->auth->id());
        $this->audit('build.retry', 'build', $new['id'], ['via' => 'api']);

        return Response::json(['data' => $this->buildJson($this->buildOr404($new['id']))], 201);
    }

    /** @param array<string, string> $params */
    public function stats(Request $request, array $params): Response
    {
        $days = min(90, max(1, $request->int('days', 7)));

        return Response::json(['data' => (new Stats($this->app->db()))->summary($days), 'days' => $days]);
    }

    /**
     * @param array<string, mixed> $p
     * @return array<string, mixed>
     */
    private function projectJson(array $p): array
    {
        return [
            'slug' => $p['slug'],
            'name' => $p['name'],
            'provider' => $p['provider'],
            'repo_url' => $p['repo_url'],
            'default_branch' => $p['default_branch'],
            'active' => (bool) $p['active'],
            'webhook_url' => $this->app->config->appUrl() . '/webhook/' . $p['slug'],
            'success_rate_30d' => $p['success_rate'] ?? null,
            'last_build' => isset($p['last_build']) && is_array($p['last_build']) ? [
                'id' => (int) $p['last_build']['id'],
                'number' => (int) $p['last_build']['number'],
                'status' => $p['last_build']['status'],
            ] : null,
        ];
    }

    /**
     * @param array<string, mixed> $b
     * @return array<string, mixed>
     */
    private function buildJson(array $b): array
    {
        $iso = static fn($v) => $v ? str_replace(' ', 'T', (string) $v) . 'Z' : null;

        return [
            'id' => (int) $b['id'],
            'project' => $b['project_slug'] ?? null,
            'number' => (int) $b['number'],
            'status' => $b['status'],
            'trigger' => $b['trigger_kind'],
            'branch' => $b['branch'],
            'tag' => $b['tag'],
            'commit' => $b['commit_sha'],
            'message' => $b['commit_message'],
            'author' => $b['commit_author'],
            'pull_request' => $b['pr_number'] !== null ? (int) $b['pr_number'] : null,
            'deploy' => (bool) $b['is_deploy'],
            'queued_at' => $iso($b['queued_at']),
            'started_at' => $iso($b['started_at']),
            'finished_at' => $iso($b['finished_at']),
            'duration_ms' => $b['duration_ms'] !== null ? (int) $b['duration_ms'] : null,
            'error' => $b['error_message'] ?? null,
            'url' => $this->app->config->appUrl() . '/builds/' . $b['id'],
        ];
    }
}
