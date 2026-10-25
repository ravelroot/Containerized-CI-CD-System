<?php

declare(strict_types=1);

namespace Vau\Http\Controller;

use Vau\App;
use Vau\Http\Charts;
use Vau\Http\Request;
use Vau\Http\Response;
use Vau\Metrics\Stats;
use Vau\Webhook\WebhookHandler;

/** Rotas públicas: webhooks, saúde, métricas e selo de status. */
final class HookController extends Controller
{
    /** @param array<string, string> $params */
    public function webhook(Request $request, array $params): Response
    {
        $handler = new WebhookHandler(
            $this->repos,
            $this->app->queue(),
            $this->app->logger(),
            max(1, $this->app->config->int('VAU_MAX_PAYLOAD_KB', 2048)) * 1024,
        );
        $result = $handler->handle($params['slug'], $request->headers, $request->body, $request->ip);

        return Response::json($result->toArray($this->app->config->appUrl(), $params['slug']), $result->status);
    }

    /** @param array<string, string> $params */
    public function health(Request $request, array $params): Response
    {
        $checks = ['banco' => 'ok'];
        $status = 200;
        try {
            $this->app->db()->value('SELECT 1');
        } catch (\Throwable) {
            $checks['banco'] = 'falhou';
            $status = 503;
        }
        $stale = $this->app->config->int('VAU_WORKER_STALE_SECONDS', 120);
        if ($status === 200) {
            $checks['workers_ativos'] = $this->repos->workers()->alive($stale);
            $checks['fila'] = $this->app->queue()->queued();
        }

        return Response::json(['status' => $status === 200 ? 'ok' : 'degradado', 'versao' => App::VERSION, 'checks' => $checks], $status);
    }

    /** @param array<string, string> $params */
    public function metrics(Request $request, array $params): Response
    {
        $token = $this->app->config->get('VAU_METRICS_TOKEN');
        if ($token !== '' && !hash_equals($token, (string) $request->bearerToken())) {
            return Response::text("não autorizado\n", 401);
        }

        return Response::text((new Stats($this->app->db()))->prometheus(), 200, 'text/plain; version=0.0.4');
    }

    /** @param array<string, string> $params */
    public function badge(Request $request, array $params): Response
    {
        $slug = (string) preg_replace('/\.svg$/', '', $params['slug']);
        $project = $this->repos->projects()->findBySlug($slug);
        $status = 'none';
        if ($project !== null) {
            $branch = $request->str('branch', (string) $project['default_branch']);
            $last = $this->app->db()->value(
                "SELECT status FROM va_build WHERE project_id = ? AND branch = ? AND status <> 'canceled' ORDER BY id DESC LIMIT 1",
                [(int) $project['id'], $branch],
            );
            $status = $last !== null ? (string) $last : 'none';
        }

        return (new Response(Charts::badge('vau', $status), 200, [
            'Content-Type' => 'image/svg+xml; charset=utf-8',
            'Cache-Control' => 'no-cache, max-age=0',
        ]));
    }
}
