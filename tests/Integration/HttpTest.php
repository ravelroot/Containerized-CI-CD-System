<?php

declare(strict_types=1);

namespace Vau\Tests\Integration;

use Vau\Http\Kernel;
use Vau\Http\Request;
use Vau\Http\Response;
use Vau\Http\Session;
use Vau\Tests\TestCase;

/** Painel, API e rotas públicas passando pelo Kernel completo. */
final class HttpTest extends TestCase
{
    /** @var array<string, string> */
    private array $cookies = [];

    private function call(string $method, string $path, array $post = [], array $headers = [], string $body = '', array $query = []): Response
    {
        $request = new Request($method, $path, $query, $post, $headers, $this->cookies, $body, '127.0.0.1', false);
        $response = (new Kernel($this->app))->handle($request);
        if (isset($response->headers['Set-Cookie']) && preg_match('/' . Session::COOKIE . '=([^;]*)/', $response->headers['Set-Cookie'], $m) === 1) {
            $this->cookies[Session::COOKIE] = $m[1];
        }

        return $response;
    }

    private function csrf(): string
    {
        $html = $this->call('GET', '/entrar')->body;
        if (!str_contains($html, '_csrf')) {
            $html = $this->call('GET', '/sistema')->body;
        }
        preg_match('/name="_csrf" value="([^"]+)"/', $html, $m);

        return $m[1] ?? '';
    }

    private function login(string $user = 'admin', string $password = 'Senha2026'): void
    {
        $this->app->repos()->users()->create($user, 'Admin', $password, 'admin');
        $response = $this->call('POST', '/entrar', ['_csrf' => $this->csrf(), 'usuario' => $user, 'senha' => $password]);
        self::assertSame(303, $response->status);
    }

    public function testPanelRequiresLoginAndCsrf(): void
    {
        $response = $this->call('GET', '/');
        self::assertSame(303, $response->status);
        self::assertSame('/entrar', $response->headers['Location']);

        $this->login();
        self::assertSame(200, $this->call('GET', '/')->status);
        self::assertSame(419, $this->call('POST', '/projetos', ['name' => 'x'])->status);
    }

    public function testLoginLockout(): void
    {
        $this->app->repos()->users()->create('ana', 'Ana', 'Senha2026', 'leitor');
        for ($i = 0; $i < 5; $i++) {
            $this->call('POST', '/entrar', ['_csrf' => $this->csrf(), 'usuario' => 'ana', 'senha' => 'errada']);
        }
        $response = $this->call('POST', '/entrar', ['_csrf' => $this->csrf(), 'usuario' => 'ana', 'senha' => 'Senha2026']);
        self::assertSame(422, $response->status);
        self::assertStringContainsString('tentativas', $response->body);
    }

    public function testCreateProjectAndViewPages(): void
    {
        $this->login();
        $csrf = $this->csrf();
        $response = $this->call('POST', '/projetos', [
            '_csrf' => $csrf,
            'name' => 'Loja Virtual',
            'provider' => 'github',
            'repo_url' => 'https://github.com/acme/loja.git',
            'default_branch' => 'main',
            'events' => ['push'],
            'timeout_minutes' => '20',
            'cpus' => '1',
            'memory' => '1g',
            'network' => 'bridge',
        ]);
        self::assertSame(303, $response->status);
        self::assertStringStartsWith('/projetos/loja-virtual', $response->headers['Location']);

        foreach (['/projetos', '/projetos/loja-virtual', '/projetos/loja-virtual?aba=webhook', '/projetos/loja-virtual?aba=pipeline', '/projetos/loja-virtual?aba=segredos', '/projetos/loja-virtual?aba=configuracoes', '/builds', '/entregas', '/sistema'] as $url) {
            [$path, $q] = array_pad(explode('?', $url, 2), 2, '');
            parse_str($q, $query);
            self::assertSame(200, $this->call('GET', $path, [], [], '', $query)->status, $url);
        }
        $run = $this->call('POST', '/projetos/loja-virtual/executar', ['_csrf' => $csrf, 'branch' => 'main']);
        self::assertSame(303, $run->status);
        self::assertSame(200, $this->call('GET', '/builds/1')->status);
        $log = json_decode($this->call('GET', '/builds/1/log')->body, true);
        self::assertSame('queued', $log['status']);
    }

    public function testApiWithToken(): void
    {
        $userId = $this->app->repos()->users()->create('bot', 'Bot', 'Senha2026', 'operador');
        $token = $this->app->repos()->tokens()->create($userId, 'ci')['token'];
        $project = $this->project();
        $auth = ['authorization' => 'Bearer ' . $token];

        self::assertSame(401, $this->call('GET', '/api/v1/projects')->status);
        $list = json_decode($this->call('GET', '/api/v1/projects', [], $auth)->body, true);
        self::assertSame('loja', $list['data'][0]['slug']);

        $created = $this->call('POST', '/api/v1/projects/loja/builds', [], $auth + ['content-type' => 'application/json'], '{"branch":"develop"}');
        self::assertSame(201, $created->status);
        $build = json_decode($created->body, true)['data'];
        self::assertSame('develop', $build['branch']);
        self::assertSame('api', $build['trigger']);

        self::assertSame(202, $this->call('POST', '/api/v1/builds/' . $build['id'] . '/cancel', [], $auth)->status);
        self::assertSame((int) $project['id'], (int) $this->app->repos()->builds()->find($build['id'])['project_id']);
    }

    public function testPublicEndpoints(): void
    {
        $this->project();
        $health = $this->call('GET', '/health');
        self::assertSame(200, $health->status);
        self::assertStringContainsString('vau_queue_depth 0', $this->call('GET', '/metrics')->body);
        $badge = $this->call('GET', '/badge/loja.svg');
        self::assertStringContainsString('sem builds', $badge->body);
        self::assertSame(404, $this->call('GET', '/nao-existe')->status);

        $body = (string) file_get_contents(__DIR__ . '/../fixtures/github-push.json');
        $hook = $this->call('POST', '/webhook/loja', [], ['x-github-event' => 'push', 'x-hub-signature-256' => 'sha256=00'], $body);
        self::assertSame(401, $hook->status);
    }

    public function testSecurityHeaders(): void
    {
        $response = $this->call('GET', '/entrar');
        self::assertSame('DENY', $response->headers['X-Frame-Options']);
        self::assertStringContainsString("default-src 'self'", $response->headers['Content-Security-Policy']);
        self::assertStringContainsString('HttpOnly', $response->headers['Set-Cookie'] ?? 'HttpOnly');
    }
}
