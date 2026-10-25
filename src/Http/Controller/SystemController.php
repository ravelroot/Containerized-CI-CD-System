<?php

declare(strict_types=1);

namespace Vau\Http\Controller;

use Vau\App;
use Vau\Http\HttpException;
use Vau\Http\Request;
use Vau\Http\Response;
use Vau\Repository\UserRepository;
use Vau\Webhook\WebhookHandler;

final class SystemController extends Controller
{
    /** @param array<string, string> $params */
    public function deliveries(Request $request, array $params): Response
    {
        return $this->page('deliveries', ['deliveries' => $this->repos->deliveries()->recent(null, 100)]);
    }

    /** @param array<string, string> $params */
    public function delivery(Request $request, array $params): Response
    {
        $delivery = $this->repos->deliveries()->find((int) $params['id']);
        if ($delivery === null) {
            throw new HttpException(404, 'Entrega não encontrada.');
        }
        $payload = json_decode((string) $delivery['payload'], true);

        return $this->page('delivery', [
            'delivery' => $delivery,
            'headers' => json_decode((string) $delivery['headers'], true) ?: [],
            'payload' => is_array($payload) ? (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : (string) $delivery['payload'],
        ]);
    }

    /**
     * Reprocessa uma entrega guardada (útil depois de corrigir o filtro de
     * branches ou de reativar o projeto). A assinatura é conferida de novo.
     *
     * @param array<string, string> $params
     */
    public function replay(Request $request, array $params): Response
    {
        $delivery = $this->repos->deliveries()->find((int) $params['id']);
        if ($delivery === null || $delivery['project_slug'] === null) {
            throw new HttpException(404, 'Entrega não encontrada ou sem projeto.');
        }
        if ((int) $delivery['payload_size'] > strlen((string) $delivery['payload'])) {
            return $this->back('/entregas/' . $delivery['id'], 'O corpo desta entrega foi guardado cortado e não pode ser reenviado.', 'erro');
        }
        $headers = json_decode((string) $delivery['headers'], true) ?: [];
        if (($headers['x-gitlab-token'] ?? null) === '•••') {
            $project = $this->repos->projects()->findBySlug((string) $delivery['project_slug']);
            $headers['x-gitlab-token'] = $project ? $this->repos->projects()->webhookSecret($project) : '';
        }
        $handler = new WebhookHandler($this->repos, $this->app->queue(), $this->app->logger(), PHP_INT_MAX);
        $result = $handler->handle((string) $delivery['project_slug'], $headers, (string) $delivery['payload'], $request->ip, true);
        $this->audit('delivery.replay', 'delivery', (int) $delivery['id'], ['resultado' => $result->status]);
        if ($result->buildId !== null) {
            return $this->back('/builds/' . $result->buildId, $result->message);
        }

        return $this->back('/entregas/' . ($result->deliveryId ?? $delivery['id']), "Reenviada: {$result->message}", $result->status >= 400 ? 'erro' : 'ok');
    }

    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        $userId = (int) $this->ctx->auth->id();
        $runtime = $this->app->runtime()->check();

        return $this->page('system', [
            'config' => $this->app->config->publicSummary(),
            'runtimeCheck' => $runtime,
            'runtimeName' => $this->app->config->runtime(),
            'driver' => $this->app->db()->driver,
            'workers' => $this->repos->workers()->all(),
            'users' => $this->ctx->auth->can('admin') ? $this->repos->users()->all() : [],
            'tokens' => $this->repos->tokens()->forUser($userId),
            'audit' => $this->ctx->auth->can('admin') ? $this->repos->audit()->recent(25) : [],
            'newToken' => $this->ctx->session?->get('novo_token'),
            'php' => PHP_VERSION,
            'version' => App::VERSION,
        ] + $this->forgetNewToken());
    }

    /** @return array<string, mixed> */
    private function forgetNewToken(): array
    {
        $this->ctx->session?->forget('novo_token');

        return [];
    }

    /** @param array<string, string> $params */
    public function password(Request $request, array $params): Response
    {
        $user = $this->ctx->auth->user() ?? [];
        if (!password_verify((string) $request->input('atual', ''), (string) ($user['password_hash'] ?? ''))) {
            return $this->back('/sistema#conta', 'A senha atual não confere.', 'erro');
        }
        $new = (string) $request->input('nova', '');
        if ($new !== (string) $request->input('confirmacao', '')) {
            return $this->back('/sistema#conta', 'A confirmação não bate com a nova senha.', 'erro');
        }
        try {
            $this->repos->users()->setPassword((int) $user['id'], $new);
        } catch (\InvalidArgumentException $e) {
            return $this->back('/sistema#conta', $e->getMessage(), 'erro');
        }
        $this->audit('user.password', 'user', (int) $user['id']);

        return $this->back('/sistema#conta', 'Senha trocada.');
    }

    /** @param array<string, string> $params */
    public function createToken(Request $request, array $params): Response
    {
        $days = $request->int('dias', 0);
        $created = $this->repos->tokens()->create((int) $this->ctx->auth->id(), $request->str('nome', 'token'), $days > 0 ? $days : null);
        $this->ctx->session?->set('novo_token', $created['token']);
        $this->audit('token.create', 'token', $created['id']);

        return $this->back('/sistema#tokens', 'Token criado. Copie agora: ele não será mostrado de novo.');
    }

    /** @param array<string, string> $params */
    public function revokeToken(Request $request, array $params): Response
    {
        $this->repos->tokens()->revoke((int) $params['id'], (int) $this->ctx->auth->id());
        $this->audit('token.revoke', 'token', (int) $params['id']);

        return $this->back('/sistema#tokens', 'Token revogado.');
    }

    /** @param array<string, string> $params */
    public function createUser(Request $request, array $params): Response
    {
        try {
            $id = $this->repos->users()->create($request->str('usuario'), $request->str('nome'), (string) $request->input('senha', ''), $request->str('perfil', 'leitor'));
        } catch (\InvalidArgumentException $e) {
            return $this->back('/sistema#usuarios', $e->getMessage(), 'erro');
        }
        $this->audit('user.create', 'user', $id, ['perfil' => $request->str('perfil')]);

        return $this->back('/sistema#usuarios', 'Usuário criado.');
    }

    /** @param array<string, string> $params */
    public function updateUser(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $target = $this->repos->users()->find($id);
        if ($target === null) {
            throw new HttpException(404, 'Usuário não encontrado.');
        }
        $role = $request->str('perfil', (string) $target['role']);
        if (!isset(UserRepository::ROLES[$role])) {
            return $this->back('/sistema#usuarios', 'Perfil inválido.', 'erro');
        }
        $active = $request->input('ativo') ? 1 : 0;
        if ($id === $this->ctx->auth->id() && ($role !== 'admin' || !$active)) {
            return $this->back('/sistema#usuarios', 'Você não pode tirar o próprio acesso de administrador.', 'erro');
        }
        $data = ['role' => $role, 'active' => $active];
        if ($request->input('desbloquear')) {
            $data['locked_until'] = null;
            $data['failed_logins'] = 0;
        }
        $this->repos->users()->update($id, $data);
        $password = (string) $request->input('senha', '');
        if ($password !== '') {
            try {
                $this->repos->users()->setPassword($id, $password);
            } catch (\InvalidArgumentException $e) {
                return $this->back('/sistema#usuarios', $e->getMessage(), 'erro');
            }
        }
        $this->audit('user.update', 'user', $id, $data);

        return $this->back('/sistema#usuarios', 'Usuário atualizado.');
    }
}
