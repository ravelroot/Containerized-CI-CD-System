<?php

declare(strict_types=1);

namespace Vau\Http\Controller;

use Vau\Http\Request;
use Vau\Http\Response;

final class AuthController extends Controller
{
    /** @param array<string, string> $params */
    public function form(Request $request, array $params): Response
    {
        if ($this->ctx->auth->user() !== null) {
            return Response::redirect('/');
        }

        return Response::html($this->ctx->view->render('login', ['username' => '', 'error' => null], null));
    }

    /** @param array<string, string> $params */
    public function login(Request $request, array $params): Response
    {
        $username = $request->str('usuario');
        $result = $this->app->auth()->attempt($username, (string) $request->input('senha', ''), $request->ip);
        if (!$result['ok']) {
            return Response::html($this->ctx->view->render('login', ['username' => $username, 'error' => $result['message']], null), 422);
        }
        $session = $this->ctx->session;
        $intended = '/';
        if ($session !== null) {
            $intended = (string) $session->get('intended', '/');
            $session->regenerate();
            $session->forget('intended');
            $session->set('uid', (int) $result['user']['id']);
        }
        if (!str_starts_with($intended, '/') || str_starts_with($intended, '//')) {
            $intended = '/';
        }

        return Response::redirect($intended);
    }

    /** @param array<string, string> $params */
    public function logout(Request $request, array $params): Response
    {
        $this->audit('logout', 'user', $this->ctx->auth->id());
        $this->ctx->session?->destroy();

        return Response::redirect('/entrar');
    }
}
