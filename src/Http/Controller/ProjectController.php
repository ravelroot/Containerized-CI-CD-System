<?php

declare(strict_types=1);

namespace Vau\Http\Controller;

use Vau\Http\Charts;
use Vau\Http\Request;
use Vau\Http\Response;
use Vau\Pipeline\Pipeline;
use Vau\Pipeline\PipelineException;

final class ProjectController extends Controller
{
    public const TABS = ['builds' => 'Builds', 'webhook' => 'Webhook', 'pipeline' => 'Pipeline', 'segredos' => 'Segredos', 'configuracoes' => 'Configurações'];

    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        $projects = $this->repos->projects()->allWithStats();
        $strips = [];
        foreach ($projects as $p) {
            $recent = array_reverse($this->repos->builds()->list(['project_id' => (int) $p['id']], 20));
            $strips[(int) $p['id']] = Charts::durationStrip(array_map(static fn($b) => [
                'status' => (string) $b['status'],
                'duration_ms' => $b['duration_ms'] !== null ? (int) $b['duration_ms'] : null,
            ], $recent));
        }

        return $this->page('projects', ['projects' => $projects, 'strips' => $strips]);
    }

    /** @param array<string, string> $params */
    public function create(Request $request, array $params): Response
    {
        return $this->page('project_form', ['form' => self::defaults($this->app->config), 'error' => null]);
    }

    /** @param array<string, string> $params */
    public function store(Request $request, array $params): Response
    {
        $data = $this->formData($request);
        try {
            $this->validatePipeline($data['pipeline_yaml']);
            $created = $this->repos->projects()->create($data, $this->ctx->auth->id());
        } catch (\InvalidArgumentException | PipelineException $e) {
            return $this->page('project_form', ['form' => $data, 'error' => $e->getMessage()], 422);
        }
        $project = $this->repos->projects()->find($created['id']);
        $this->audit('project.create', 'project', $created['id'], ['slug' => $project['slug'] ?? '']);

        return $this->back('/projetos/' . $project['slug'] . '?aba=webhook', 'Projeto criado. Agora cadastre o webhook no ' . ($project['provider'] === 'gitlab' ? 'GitLab' : 'GitHub') . ' com os dados abaixo.');
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        $project = $this->projectOr404($params['slug']);
        $tab = $request->str('aba', 'builds');
        if (!isset(self::TABS[$tab])) {
            $tab = 'builds';
        }
        $id = (int) $project['id'];
        $vars = [
            'project' => $project,
            'tab' => $tab,
            'tabs' => self::TABS,
            'error' => null,
            'form' => $project,
            'secretCount' => count($this->repos->projects()->secrets($id)),
        ];
        if ($tab === 'builds') {
            $vars['builds'] = $this->repos->builds()->list(['project_id' => $id], 40);
        } elseif ($tab === 'webhook') {
            $vars['webhookUrl'] = $this->app->config->appUrl() . '/webhook/' . $project['slug'];
            $vars['secret'] = $this->ctx->auth->can('manage') ? $this->repos->projects()->webhookSecret($project) : null;
            $vars['deliveries'] = $this->repos->deliveries()->recent($id, 15);
        } elseif ($tab === 'segredos') {
            $vars['secrets'] = $this->repos->projects()->secrets($id);
        }

        return $this->page('project', $vars);
    }

    /** @param array<string, string> $params */
    public function update(Request $request, array $params): Response
    {
        $project = $this->projectOr404($params['slug']);
        if ($request->input('_so_pipeline')) {
            // aba Pipeline: só o YAML muda; o resto vem do cadastro atual
            $data = array_merge($project, [
                'events' => array_filter(explode(',', (string) $project['events'])),
                'pipeline_yaml' => (string) $request->input('pipeline_yaml', ''),
                'branch_filter' => (string) ($project['branch_filter'] ?? ''),
                'notify_url' => (string) ($project['notify_url'] ?? ''),
            ]);
            unset($data['access_token']);
        } else {
            $data = $this->formData($request);
            $data['pipeline_yaml'] = (string) ($project['pipeline_yaml'] ?? '');
            $data['active'] = $request->input('active') ? 1 : 0;
        }
        try {
            $this->validatePipeline($data['pipeline_yaml']);
            $this->repos->projects()->update((int) $project['id'], $data);
        } catch (\InvalidArgumentException | PipelineException $e) {
            return $this->page('project', [
                'project' => $project,
                'tab' => $request->str('aba', 'configuracoes'),
                'tabs' => self::TABS,
                'form' => array_merge($project, $data),
                'error' => $e->getMessage(),
                'secretCount' => count($this->repos->projects()->secrets((int) $project['id'])),
            ], 422);
        }
        $this->audit('project.update', 'project', (int) $project['id']);
        $tab = $request->str('aba', 'configuracoes');

        return $this->back('/projetos/' . $project['slug'] . '?aba=' . (isset(self::TABS[$tab]) ? $tab : 'configuracoes'), 'Alterações salvas.');
    }

    /** @param array<string, string> $params */
    public function destroy(Request $request, array $params): Response
    {
        $project = $this->projectOr404($params['slug']);
        if ($request->str('confirmar') !== $project['slug']) {
            return $this->back('/projetos/' . $project['slug'] . '?aba=configuracoes', 'Digite o identificador do projeto para confirmar a exclusão.', 'erro');
        }
        $this->repos->projects()->delete((int) $project['id']);
        $this->audit('project.delete', 'project', (int) $project['id'], ['slug' => $project['slug']]);

        return $this->back('/projetos', "Projeto {$project['name']} excluído com todos os builds.");
    }

    /** @param array<string, string> $params */
    public function run(Request $request, array $params): Response
    {
        $project = $this->projectOr404($params['slug']);
        try {
            $build = $this->app->queue()->enqueueManual($project, $request->str('branch'), $request->str('commit'), $this->ctx->auth->id());
        } catch (\InvalidArgumentException $e) {
            return $this->back('/projetos/' . $project['slug'], $e->getMessage(), 'erro');
        }
        $this->audit('build.manual', 'build', $build['id']);

        return $this->back('/builds/' . $build['id'], "Build #{$build['number']} enfileirado.");
    }

    /** @param array<string, string> $params */
    public function rotateSecret(Request $request, array $params): Response
    {
        $project = $this->projectOr404($params['slug']);
        $this->repos->projects()->rotateSecret((int) $project['id']);
        $this->audit('project.secret_rotate', 'project', (int) $project['id']);

        return $this->back('/projetos/' . $project['slug'] . '?aba=webhook', 'Segredo trocado. Atualize o webhook no provedor, ou as próximas entregas serão recusadas.');
    }

    /** @param array<string, string> $params */
    public function putSecret(Request $request, array $params): Response
    {
        $project = $this->projectOr404($params['slug']);
        $name = strtoupper($request->str('nome'));
        try {
            $this->repos->projects()->putSecret((int) $project['id'], $name, (string) $request->input('valor', ''), (bool) $request->input('somente_deploy'));
        } catch (\InvalidArgumentException $e) {
            return $this->back('/projetos/' . $project['slug'] . '?aba=segredos', $e->getMessage(), 'erro');
        }
        $this->audit('secret.put', 'project', (int) $project['id'], ['nome' => $name]);

        return $this->back('/projetos/' . $project['slug'] . '?aba=segredos', "Segredo {$name} gravado. O valor não volta a aparecer.");
    }

    /** @param array<string, string> $params */
    public function deleteSecret(Request $request, array $params): Response
    {
        $project = $this->projectOr404($params['slug']);
        $this->repos->projects()->deleteSecret((int) $project['id'], (int) $params['id']);
        $this->audit('secret.delete', 'project', (int) $project['id'], ['segredo' => (int) $params['id']]);

        return $this->back('/projetos/' . $project['slug'] . '?aba=segredos', 'Segredo removido.');
    }

    /** @return array<string, mixed> */
    private function formData(Request $request): array
    {
        return [
            'name' => $request->str('name'),
            'provider' => $request->str('provider', 'github'),
            'repo_url' => $request->str('repo_url'),
            'default_branch' => $request->str('default_branch', 'main'),
            'branch_filter' => $request->str('branch_filter'),
            'events' => (array) $request->input('events', []),
            'pipeline_yaml' => (string) $request->input('pipeline_yaml', ''),
            'timeout_minutes' => $request->int('timeout_minutes', 30),
            'cpus' => $request->str('cpus', '1.0'),
            'memory' => $request->str('memory', '1g'),
            'network' => $request->str('network', 'bridge'),
            'auto_cancel' => (bool) $request->input('auto_cancel'),
            'allow_deploy' => (bool) $request->input('allow_deploy'),
            'report_status' => (bool) $request->input('report_status'),
            'notify_url' => $request->str('notify_url'),
            'notify_on' => $request->str('notify_on', 'falhas'),
            'access_token' => (string) $request->input('access_token', ''),
        ];
    }

    private function validatePipeline(string $yaml): void
    {
        if (trim($yaml) !== '') {
            Pipeline::fromString($yaml, 'pipeline padrão', $this->app->config->get('VAU_DEFAULT_IMAGE'));
        }
    }

    /**
     * Valores iniciais do formulário de projeto novo, vindos do .env
     * (VAU_BUILD_TIMEOUT, VAU_CPUS, VAU_MEMORY e VAU_NETWORK).
     *
     * @return array<string, mixed>
     */
    public static function defaults(\Vau\Config $config): array
    {
        $network = $config->get('VAU_NETWORK', 'bridge');

        return [
            'name' => '',
            'provider' => 'github',
            'repo_url' => '',
            'default_branch' => 'main',
            'branch_filter' => '',
            'events' => 'push,pull_request,tag',
            'pipeline_yaml' => '',
            'timeout_minutes' => max(1, min(360, $config->int('VAU_BUILD_TIMEOUT', 30))),
            'cpus' => $config->get('VAU_CPUS', '1.0'),
            'memory' => $config->get('VAU_MEMORY', '1g'),
            'network' => in_array($network, ['bridge', 'none'], true) ? $network : 'bridge',
            'auto_cancel' => 1,
            'allow_deploy' => 1,
            'report_status' => 1,
            'notify_url' => '',
            'notify_on' => 'falhas',
        ];
    }
}
