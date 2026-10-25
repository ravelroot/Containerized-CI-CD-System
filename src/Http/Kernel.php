<?php

declare(strict_types=1);

namespace Vau\Http;

use Vau\App;
use Vau\Http\Controller\ApiController;
use Vau\Http\Controller\AuthController;
use Vau\Http\Controller\BuildController;
use Vau\Http\Controller\Context;
use Vau\Http\Controller\DashboardController;
use Vau\Http\Controller\HookController;
use Vau\Http\Controller\ProjectController;
use Vau\Http\Controller\SystemController;
use Vau\Support\Time;

/**
 * Recebe a requisição, aplica as camadas comuns (cabeçalhos de segurança,
 * sessão, autenticação, CSRF, limite de taxa, tratamento de erros) e
 * entrega para o controlador da rota.
 */
final class Kernel
{
    private Router $router;

    public function __construct(private readonly App $app)
    {
        $this->router = new Router();
        $this->routes();
    }

    private function routes(): void
    {
        $r = $this->router;
        $public = ['public' => true];
        $noSession = ['public' => true, 'session' => false];

        // públicas, sem sessão (webhooks, monitoramento, selo)
        $r->post('/webhook/{slug}', [HookController::class, 'webhook'], $noSession);
        $r->get('/health', [HookController::class, 'health'], $noSession);
        $r->get('/metrics', [HookController::class, 'metrics'], $noSession);
        $r->get('/badge/{slug}', [HookController::class, 'badge'], $noSession);

        // entrada
        $r->get('/entrar', [AuthController::class, 'form'], $public);
        $r->post('/entrar', [AuthController::class, 'login'], $public + ['rate' => 'login']);
        $r->post('/sair', [AuthController::class, 'logout']);

        // painel
        $r->get('/', [DashboardController::class, 'index']);
        $r->get('/builds', [BuildController::class, 'index']);
        $r->get('/builds/{id}', [BuildController::class, 'show']);
        $r->get('/builds/{id}/log', [BuildController::class, 'log']);
        $r->get('/builds/{id}/log.txt', [BuildController::class, 'raw']);
        $r->post('/builds/{id}/cancelar', [BuildController::class, 'cancel'], ['can' => 'build']);
        $r->post('/builds/{id}/repetir', [BuildController::class, 'retry'], ['can' => 'build']);

        $r->get('/projetos', [ProjectController::class, 'index']);
        $r->get('/projetos/novo', [ProjectController::class, 'create'], ['can' => 'manage']);
        $r->post('/projetos', [ProjectController::class, 'store'], ['can' => 'manage']);
        $r->get('/projetos/{slug}', [ProjectController::class, 'show']);
        $r->post('/projetos/{slug}', [ProjectController::class, 'update'], ['can' => 'manage']);
        $r->post('/projetos/{slug}/excluir', [ProjectController::class, 'destroy'], ['can' => 'admin']);
        $r->post('/projetos/{slug}/executar', [ProjectController::class, 'run'], ['can' => 'build']);
        $r->post('/projetos/{slug}/segredo-webhook', [ProjectController::class, 'rotateSecret'], ['can' => 'manage']);
        $r->post('/projetos/{slug}/segredos', [ProjectController::class, 'putSecret'], ['can' => 'manage']);
        $r->post('/projetos/{slug}/segredos/{id}/excluir', [ProjectController::class, 'deleteSecret'], ['can' => 'manage']);

        $r->get('/entregas', [SystemController::class, 'deliveries']);
        $r->get('/entregas/{id}', [SystemController::class, 'delivery']);
        $r->post('/entregas/{id}/reenviar', [SystemController::class, 'replay'], ['can' => 'manage']);

        $r->get('/sistema', [SystemController::class, 'index']);
        $r->post('/sistema/senha', [SystemController::class, 'password']);
        $r->post('/sistema/tokens', [SystemController::class, 'createToken']);
        $r->post('/sistema/tokens/{id}/revogar', [SystemController::class, 'revokeToken']);
        $r->post('/sistema/usuarios', [SystemController::class, 'createUser'], ['can' => 'admin']);
        $r->post('/sistema/usuarios/{id}', [SystemController::class, 'updateUser'], ['can' => 'admin']);

        // API REST com token
        $api = ['api' => true];
        $r->get('/api/v1/projects', [ApiController::class, 'projects'], $api);
        $r->get('/api/v1/projects/{slug}', [ApiController::class, 'project'], $api);
        $r->get('/api/v1/projects/{slug}/builds', [ApiController::class, 'builds'], $api);
        $r->post('/api/v1/projects/{slug}/builds', [ApiController::class, 'trigger'], $api + ['can' => 'build']);
        $r->get('/api/v1/builds/{id}', [ApiController::class, 'build'], $api);
        $r->get('/api/v1/builds/{id}/log', [ApiController::class, 'log'], $api);
        $r->post('/api/v1/builds/{id}/cancel', [ApiController::class, 'cancel'], $api + ['can' => 'build']);
        $r->post('/api/v1/builds/{id}/retry', [ApiController::class, 'retry'], $api + ['can' => 'build']);
        $r->get('/api/v1/stats', [ApiController::class, 'stats'], $api);
    }

    public function handle(Request $request): Response
    {
        $requestId = bin2hex(random_bytes(6));
        $started = hrtime(true);
        try {
            $response = $this->dispatch($request);
        } catch (HttpException $e) {
            $response = $this->error($request, $e->status, $e->getMessage());
        } catch (\Throwable $e) {
            $this->app->logger()->error('Erro não tratado', ['request' => $requestId, 'path' => $request->path, 'exception' => $e]);
            $response = $this->error($request, 500, "Algo deu errado do nosso lado. Código do erro: {$requestId}.");
        }

        $response->withHeader('X-Request-Id', $requestId);
        $response->withHeader('X-Content-Type-Options', 'nosniff');
        $response->withHeader('Referrer-Policy', 'same-origin');
        $response->withHeader('X-Frame-Options', 'DENY');
        $response->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        if (!isset($response->headers['Content-Security-Policy'])) {
            $response->withHeader('Content-Security-Policy', "default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; form-action 'self'; base-uri 'none'");
        }
        if ($request->secure) {
            $response->withHeader('Strict-Transport-Security', 'max-age=31536000');
        }
        $this->app->logger()->debug('HTTP', [
            'method' => $request->method,
            'path' => $request->path,
            'status' => $response->status,
            'ms' => round((hrtime(true) - $started) / 1e6, 1),
            'request' => $requestId,
        ]);

        return $response;
    }

    private function dispatch(Request $request): Response
    {
        $match = $this->router->match($request->method, $request->path);
        if ($match === 404) {
            throw new HttpException(404, 'Página não encontrada.');
        }
        if ($match === 405) {
            throw new HttpException(405, 'Método não permitido nesta rota.');
        }
        [$handler, $params, $options] = $match;
        $config = $this->app->config;
        $auth = $this->app->auth();

        if (isset($options['rate']) && !$this->allow($options['rate'] . ':' . $request->ip, 10, 60)) {
            throw new HttpException(429, 'Muitas tentativas em pouco tempo. Aguarde um minuto.');
        }
        if (str_starts_with($request->path, '/webhook/') && !$this->allow('webhook:' . $request->ip, max(1, $config->int('VAU_WEBHOOK_RATE_LIMIT', 120)), 60)) {
            return Response::json(['status' => 429, 'mensagem' => 'Limite de requisições atingido.'], 429)->withHeader('Retry-After', '60');
        }

        $session = null;
        if (!empty($options['api'])) {
            $token = $request->bearerToken();
            $user = $token !== null ? $this->app->repos()->tokens()->authenticate($token) : null;
            if ($user === null) {
                return Response::json(['erro' => 'Token ausente, inválido ou expirado. Envie Authorization: Bearer <token>.'], 401)
                    ->withHeader('WWW-Authenticate', 'Bearer');
            }
            $auth->setUser($user);
        } elseif (($options['session'] ?? true) !== false) {
            $session = Session::start($this->app->db(), $request, max(5, $config->int('VAU_SESSION_MINUTES', 480)) * 60);
            $uid = $session->get('uid');
            if (is_int($uid)) {
                $user = $this->app->repos()->users()->find($uid);
                if ($user !== null && (int) $user['active']) {
                    $auth->setUser($user);
                } else {
                    $session->forget('uid');
                }
            }
            if (empty($options['public']) && $auth->user() === null) {
                $session->set('intended', $request->method === 'GET' ? $request->path : '/');
                $response = Response::redirect('/entrar');
                $session->save($response, $request->secure, $request->ip, $request->header('user-agent'));

                return $response;
            }
            if ($request->method !== 'GET' && $request->method !== 'HEAD' && !$session->validCsrf($request->str('_csrf') ?: $request->header('x-csrf-token'))) {
                throw new HttpException(419, 'A página ficou aberta por muito tempo. Recarregue e tente de novo.');
            }
        }

        if (isset($options['can']) && !$auth->can((string) $options['can'])) {
            throw new HttpException(403, 'Seu perfil não tem permissão para esta ação.');
        }

        $view = new View($this->app->root . '/templates', $config->timezone());
        $view->share('user', $auth->user());
        $view->share('path', $request->path);
        if ($session !== null) {
            $view->share('csrf', $session->csrfToken());
            $view->share('flash', $session->takeFlash());
        }
        $view->share('appName', App::NAME);
        $view->share('version', App::VERSION);

        $context = new Context($this->app, $request, $view, $session, $auth);
        [$class, $method] = $handler;
        $controller = new $class($context);
        /** @var Response $response */
        $response = $controller->$method($request, $params);

        if ($session !== null) {
            $session->save($response, $request->secure, $request->ip, $request->header('user-agent'));
        }

        return $response;
    }

    /** Limite de taxa por janela fixa, guardado em va_rate_limit. */
    private function allow(string $bucket, int $max, int $window): bool
    {
        $db = $this->app->db();
        $now = Time::ts();
        $bucket = substr(hash('sha256', $bucket), 0, 40);
        try {
            return $db->transaction(function () use ($db, $bucket, $max, $window, $now): bool {
                $row = $db->one('SELECT hits, window_start FROM va_rate_limit WHERE bucket = ?', [$bucket]);
                if ($row === null) {
                    $db->insert('va_rate_limit', ['bucket' => $bucket, 'hits' => 1, 'window_start' => $now], false);

                    return true;
                }
                if ((int) $row['window_start'] <= $now - $window) {
                    $db->update('va_rate_limit', ['hits' => 1, 'window_start' => $now], ['bucket' => $bucket]);

                    return true;
                }
                $db->run('UPDATE va_rate_limit SET hits = hits + 1 WHERE bucket = ?', [$bucket]);

                return (int) $row['hits'] < $max;
            });
        } catch (\PDOException) {
            return true; // o limitador nunca derruba a aplicação
        }
    }

    private function error(Request $request, int $status, string $message): Response
    {
        if ($request->wantsJson() || str_starts_with($request->path, '/webhook/')) {
            return Response::json(['status' => $status, 'erro' => $message], $status);
        }
        try {
            $view = new View($this->app->root . '/templates', $this->app->config->timezone());
            $view->share('appName', App::NAME);

            return Response::html($view->render('error', ['status' => $status, 'message' => $message], null), $status);
        } catch (\Throwable) {
            return Response::text("{$status} · {$message}", $status);
        }
    }
}
