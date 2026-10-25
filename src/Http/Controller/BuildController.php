<?php

declare(strict_types=1);

namespace Vau\Http\Controller;

use Vau\Http\Request;
use Vau\Http\Response;
use Vau\Repository\BuildRepository;

final class BuildController extends Controller
{
    private const PER_PAGE = 30;

    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        $filters = [];
        $projectSlug = $request->str('projeto');
        if ($projectSlug !== '') {
            $project = $this->repos->projects()->findBySlug($projectSlug);
            $filters['project_id'] = $project ? (int) $project['id'] : -1;
        }
        $status = $request->str('status');
        if (isset(BuildRepository::STATUS[$status])) {
            $filters['status'] = $status;
        }
        $page = max(1, $request->int('pagina', 1));
        $total = $this->repos->builds()->count($filters);

        return $this->page('builds', [
            'builds' => $this->repos->builds()->list($filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'total' => $total,
            'pageNumber' => $page,
            'pages' => max(1, (int) ceil($total / self::PER_PAGE)),
            'projects' => $this->repos->projects()->allWithStats(),
            'filterProject' => $projectSlug,
            'filterStatus' => $status,
        ]);
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        $build = $this->buildOr404((int) $params['id']);
        $delivery = $build['delivery_id'] ? $this->repos->deliveries()->find((int) $build['delivery_id']) : null;

        return $this->page('build', [
            'build' => $build,
            'steps' => $this->repos->builds()->steps((int) $build['id']),
            'delivery' => $delivery,
            'retryOf' => $build['retry_of'] ? $this->repos->builds()->find((int) $build['retry_of']) : null,
        ]);
    }

    /**
     * Leitura incremental do log para o painel: devolve os trechos depois de
     * "depois" e o estado atual do build e das etapas.
     *
     * @param array<string, string> $params
     */
    public function log(Request $request, array $params): Response
    {
        $build = $this->buildOr404((int) $params['id']);
        $after = max(0, $request->int('depois', 0));
        $chunks = $this->repos->builds()->logsAfter((int) $build['id'], $after, 400);
        $steps = array_map(static fn(array $s) => [
            'id' => (int) $s['id'],
            'status' => $s['status'],
            'duration_ms' => $s['duration_ms'] !== null ? (int) $s['duration_ms'] : null,
            'note' => $s['note'],
        ], $this->repos->builds()->steps((int) $build['id']));

        return Response::json([
            'status' => $build['status'],
            'finished' => in_array($build['status'], BuildRepository::FINISHED, true),
            'duration_ms' => $build['duration_ms'] !== null ? (int) $build['duration_ms'] : null,
            'started_at' => $build['started_at'],
            'error' => $build['error_message'],
            'chunks' => array_map(static fn(array $c) => [
                'id' => (int) $c['id'],
                'step' => $c['step_id'] !== null ? (int) $c['step_id'] : null,
                'stream' => $c['stream'],
                'text' => $c['content'],
            ], $chunks),
            'steps' => $steps,
        ]);
    }

    /** @param array<string, string> $params */
    public function raw(Request $request, array $params): Response
    {
        $build = $this->buildOr404((int) $params['id']);
        $name = sprintf('%s-build-%d.log', $build['project_slug'], $build['number']);

        return Response::text($this->repos->builds()->fullLog((int) $build['id']))
            ->withHeader('Content-Disposition', 'inline; filename="' . $name . '"');
    }

    /** @param array<string, string> $params */
    public function cancel(Request $request, array $params): Response
    {
        $build = $this->buildOr404((int) $params['id']);
        $result = $this->app->queue()->cancel((int) $build['id']);
        $this->audit('build.cancel', 'build', (int) $build['id']);
        $message = match ($result) {
            'canceled' => 'Build cancelado antes de começar.',
            'requested' => 'Cancelamento pedido. O container será encerrado em instantes.',
            default => 'O build já tinha terminado.',
        };

        return $this->back('/builds/' . $build['id'], $message);
    }

    /** @param array<string, string> $params */
    public function retry(Request $request, array $params): Response
    {
        $build = $this->buildOr404((int) $params['id']);
        $new = $this->app->queue()->retry((int) $build['id'], $this->ctx->auth->id());
        $this->audit('build.retry', 'build', $new['id'], ['de' => (int) $build['id']]);

        return $this->back('/builds/' . $new['id'], "Build #{$new['number']} enfileirado com os mesmos parâmetros.");
    }
}
