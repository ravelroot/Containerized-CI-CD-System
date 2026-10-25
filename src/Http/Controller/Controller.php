<?php

declare(strict_types=1);

namespace Vau\Http\Controller;

use Vau\App;
use Vau\Database\Repositories;
use Vau\Http\HttpException;
use Vau\Http\Response;

abstract class Controller
{
    protected App $app;
    protected Repositories $repos;

    public function __construct(protected readonly Context $ctx)
    {
        $this->app = $ctx->app;
        $this->repos = $ctx->app->repos();
    }

    /** @param array<string, mixed> $vars */
    protected function page(string $template, array $vars = [], int $status = 200): Response
    {
        return Response::html($this->ctx->view->render($template, $vars), $status);
    }

    protected function back(string $to, ?string $message = null, string $type = 'ok'): Response
    {
        if ($message !== null) {
            $this->ctx->session?->flash($type, $message);
        }

        return Response::redirect($to);
    }

    /** @return array<string, mixed> */
    protected function projectOr404(string $slug): array
    {
        $project = $this->repos->projects()->findBySlug($slug);
        if ($project === null) {
            throw new HttpException(404, 'Projeto não encontrado.');
        }

        return $project;
    }

    /** @return array<string, mixed> */
    protected function buildOr404(int $id): array
    {
        $build = $this->repos->builds()->find($id);
        if ($build === null) {
            throw new HttpException(404, 'Build não encontrado.');
        }

        return $build;
    }

    /** @param array<string, mixed> $details */
    protected function audit(string $action, ?string $type = null, int|string|null $id = null, array $details = []): void
    {
        $this->repos->audit()->log($this->ctx->auth->id(), $action, $type, $id, $details, $this->ctx->request->ip);
    }
}
