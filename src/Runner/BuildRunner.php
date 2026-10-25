<?php

declare(strict_types=1);

namespace Vau\Runner;

use Vau\App;
use Vau\Config;
use Vau\Database\Connection;
use Vau\Database\Repositories;
use Vau\Integrations\Notifier;
use Vau\Integrations\StatusReporter;
use Vau\Pipeline\Pipeline;
use Vau\Pipeline\PipelineException;
use Vau\Support\Logger;
use Vau\Support\Text;
use Vau\Support\Time;

/**
 * Executa um build do começo ao fim:
 *   1. cria o espaço isolado e clona o commit;
 *   2. lê .vau.yml (ou usa o pipeline padrão do projeto);
 *   3. roda as etapas em ordem, cada uma num container novo;
 *   4. roda o deploy se tudo passou e a branch estiver liberada;
 *   5. limpa containers e volume, grava o resultado e avisa o provedor.
 */
final class BuildRunner
{
    public function __construct(
        private readonly Connection $db,
        private readonly Repositories $repos,
        private readonly ContainerRuntime $runtime,
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly StatusReporter $reporter,
        private readonly Notifier $notifier,
    ) {}

    public function run(int $buildId): string
    {
        $builds = $this->repos->builds();
        $build = $builds->find($buildId);
        if ($build === null) {
            throw new \InvalidArgumentException("Build {$buildId} não existe.");
        }
        $project = $this->repos->projects()->find((int) $build['project_id']);
        if ($project === null) {
            throw new \InvalidArgumentException('Projeto do build não existe mais.');
        }
        if ($build['status'] !== 'running') {
            $builds->update($buildId, ['status' => 'running', 'started_at' => Time::now()]);
        }

        $started = hrtime(true);
        $log = new LogWriter($this->db, $buildId, max(1, $this->config->int('VAU_MAX_LOG_MB', 10)) * 1_048_576);
        $token = $this->repos->projects()->accessToken($project);
        $secrets = $this->repos->projects()->secretValues((int) $project['id']);
        $log->addSecrets(array_values(array_filter([$token, ...array_column($secrets, 'value')])));

        $ctx = new BuildContext(
            buildId: $buildId,
            number: (int) $build['number'],
            projectSlug: (string) $project['slug'],
            repoUrl: (string) $project['repo_url'],
            accessToken: $token,
            provider: (string) $project['provider'],
            gitRef: (string) $build['git_ref'],
            sha: $build['commit_sha'] !== null ? (string) $build['commit_sha'] : null,
            cpus: (string) $project['cpus'],
            memory: (string) $project['memory'],
            network: (string) $project['network'],
        );

        $this->reporter->report($project, $build, 'running');
        $status = 'error';
        $error = null;
        $exitCode = null;
        $isDeploy = false;
        $pipelineFile = null;
        $pipelineJson = null;
        $timeoutMinutes = (int) $project['timeout_minutes'];
        $deadline = microtime(true) + $timeoutMinutes * 60;
        $shouldStop = fn(): bool => $builds->isCancelRequested($buildId);

        $log->system(null, sprintf(
            '[vau] Build #%d de %s · %s %s · runtime %s · %s',
            $build['number'],
            $project['name'],
            $build['tag'] ? 'tag ' . $build['tag'] : 'branch ' . ($build['branch'] ?? '—'),
            $build['commit_sha'] ? '@ ' . Text::shortSha((string) $build['commit_sha']) : '',
            $this->runtime->name(),
            Time::local(Time::now(), $this->config->timezone(), 'd/m/Y H:i:s'),
        ));

        $position = 0;
        $cloneStep = $builds->addStep($buildId, $position++, 'Clonar repositório', 'clone', $this->config->get('VAU_CLONE_IMAGE'), null, 'running');

        try {
            $this->runtime->prepare($ctx);

            // 1. clone
            $builds->updateStep($cloneStep, ['started_at' => Time::now()]);
            $result = $this->runtime->cloneRepository($ctx, fn(string $s, string $c) => $log->write($cloneStep, $s, $c), $shouldStop);
            $this->finishStep($cloneStep, $result);
            $log->flush(true);
            if (!$result->ok()) {
                [$status, $error, $exitCode] = $this->failure($result, 'Falha ao clonar o repositório.');
                throw new BuildStopped();
            }

            // 2. pipeline
            $found = $this->runtime->readPipeline($ctx);
            try {
                if ($found !== null) {
                    $pipelineFile = $found['file'];
                    $pipeline = Pipeline::fromString($found['content'], $found['file'], $this->config->get('VAU_DEFAULT_IMAGE'));
                } elseif (!empty($project['pipeline_yaml'])) {
                    $pipelineFile = 'padrão do projeto';
                    $pipeline = Pipeline::fromString((string) $project['pipeline_yaml'], 'pipeline padrão', $this->config->get('VAU_DEFAULT_IMAGE'));
                } else {
                    throw new PipelineException('Nenhum pipeline encontrado: crie .vau.yml na raiz do repositório ou cadastre um pipeline padrão no projeto.');
                }
            } catch (PipelineException $e) {
                $log->system(null, '[vau] ' . $e->getMessage());
                $error = Text::truncate($e->getMessage(), 500);
                $status = 'error';
                throw new BuildStopped();
            }
            $pipelineJson = (string) json_encode($pipeline->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($pipeline->timeoutMinutes !== null) {
                $timeoutMinutes = min($timeoutMinutes, $pipeline->timeoutMinutes);
                $deadline = microtime(true) + $timeoutMinutes * 60;
            }
            $builds->update($buildId, ['pipeline_file' => $pipelineFile, 'pipeline_json' => $pipelineJson]);
            $log->system(null, sprintf('[vau] Pipeline: %s · %d etapa(s) · tempo limite %d min', $pipelineFile, count($pipeline->steps), $timeoutMinutes));

            // registra as etapas antes de rodar, para o painel mostrar o plano completo
            $plan = [];
            foreach ($pipeline->steps as $step) {
                $skip = $step->runsOn($build['branch']) ? null : 'Não roda nesta branch.';
                $plan[] = [$step, $builds->addStep($buildId, $position++, $step->name, 'step', $step->image, $step->run, $skip ? 'skipped' : 'pending', $skip), $skip === null];
            }
            $deployStep = null;
            // repetição de um PR continua sendo PR: nunca faz deploy
            $trigger = $build['pr_number'] !== null ? 'pull_request' : (string) $build['trigger_kind'];
            $deployAllowed = (int) $project['allow_deploy'] === 1 && $pipeline->deployAllowed($build['branch'], $build['tag'], $trigger);
            if ($pipeline->deploy !== null) {
                $note = $deployAllowed ? null : ((int) $project['allow_deploy'] === 1 ? 'Branch fora de deploy.branches ou build de pull request.' : 'Deploy desligado no projeto.');
                $deployStep = $builds->addStep($buildId, $position++, $pipeline->deploy->name, 'deploy', $pipeline->deploy->image, $pipeline->deploy->run, $deployAllowed ? 'pending' : 'skipped', $note);
            }

            $baseEnv = $this->baseEnv($project, $build) + $pipeline->env;
            $useSecrets = !(bool) $build['is_fork'];
            if (!$useSecrets && $secrets !== []) {
                $log->system(null, '[vau] Pull request de fork: os segredos do projeto não são entregues a este build.');
            }

            // 3. etapas
            $failed = false;
            foreach ($plan as $n => [$step, $stepId, $runs]) {
                if (!$runs) {
                    continue;
                }
                if ($failed) {
                    $builds->updateStep($stepId, ['status' => 'skipped', 'note' => 'Uma etapa anterior falhou.']);
                    continue;
                }
                $env = $baseEnv + $step->env + ($useSecrets ? $this->secretEnv($secrets, false) : []);
                $result = $this->runStep($ctx, $stepId, $n + 1, $step->image, $step->run, $env, $deadline, $log, $shouldStop);
                if ($result->canceled || $result->timedOut) {
                    [$status, $error, $exitCode] = $this->failure($result, '');
                    $this->skipRemaining($buildId, 'canceled');
                    throw new BuildStopped();
                }
                if (!$result->ok()) {
                    if ($step->allowFailure) {
                        $log->system($stepId, "[vau] A etapa \"{$step->name}\" falhou (código {$result->exitCode}), mas allow_failure permite seguir.");
                        continue;
                    }
                    $failed = true;
                    $exitCode = $result->exitCode;
                    $error = "A etapa \"{$step->name}\" terminou com código {$result->exitCode}.";
                }
            }

            // 4. deploy
            if (!$failed && $deployAllowed && $deployStep !== null && $pipeline->deploy !== null) {
                $isDeploy = true;
                $builds->update($buildId, ['is_deploy' => 1]);
                $deploy = $pipeline->deploy;
                $env = $baseEnv + $deploy->env + ($useSecrets ? $this->secretEnv($secrets, true) : []) + ['VAU_DEPLOY' => 'true'];
                $result = $this->runStep($ctx, $deployStep, count($plan) + 1, $deploy->image, $deploy->run, $env, $deadline, $log, $shouldStop);
                if (!$result->ok()) {
                    if ($result->canceled || $result->timedOut) {
                        [$status, $error, $exitCode] = $this->failure($result, '');
                        throw new BuildStopped();
                    }
                    $failed = true;
                    $exitCode = $result->exitCode;
                    $error = "O deploy terminou com código {$result->exitCode}.";
                }
            } elseif ($failed && $deployStep !== null && $deployAllowed) {
                $builds->updateStep($deployStep, ['status' => 'skipped', 'note' => 'Uma etapa anterior falhou.']);
            }

            $status = $failed ? 'failed' : 'success';
            $exitCode ??= 0;
        } catch (BuildStopped) {
            // status e mensagem já definidos
        } catch (\Throwable $e) {
            $status = 'error';
            $error = Text::truncate('Erro interno: ' . $e->getMessage(), 500);
            $log->system(null, '[vau] ' . $error);
            $this->logger->error('Build terminou com erro interno', ['build' => $buildId, 'exception' => $e]);
        } finally {
            try {
                $this->runtime->cleanup($ctx);
            } catch (\Throwable $e) {
                $this->logger->warning('Falha ao limpar recursos do build', ['build' => $buildId, 'exception' => $e]);
            }
        }

        if ($status === 'error' && $error === null) {
            $error = 'O build foi interrompido.';
        }
        $this->skipRemaining($buildId, $status === 'canceled' ? 'canceled' : 'skipped');
        $duration = (int) ((hrtime(true) - $started) / 1_000_000);
        $log->system(null, sprintf(
            '[vau] Resultado: %s em %s%s',
            strtoupper(\Vau\Repository\BuildRepository::STATUS[$status] ?? $status),
            Text::duration($duration),
            $error ? ' · ' . $error : '',
        ));
        $log->flush(true);

        $builds->update($buildId, [
            'status' => $status,
            'finished_at' => Time::now(),
            'duration_ms' => $duration,
            'exit_code' => $exitCode,
            'error_message' => $error,
            'is_deploy' => (int) $isDeploy,
            'log_bytes' => $log->bytes(),
        ]);

        $final = $builds->find($buildId) ?? $build;
        $this->reporter->report($project, $final, $status);
        $this->notifier->notify($project, $final, $builds->previousFinishedStatus((int) $project['id'], $final['branch'], $buildId));
        $this->logger->info('Build terminado', ['build' => $buildId, 'status' => $status, 'ms' => $duration]);

        return $status;
    }

    /** @param array<string, string> $env */
    private function runStep(BuildContext $ctx, int $stepId, int $position, string $image, string $script, array $env, float $deadline, LogWriter $log, callable $shouldStop): StepResult
    {
        $builds = $this->repos->builds();
        $remaining = (int) floor($deadline - microtime(true));
        if ($remaining <= 0) {
            $builds->updateStep($stepId, ['status' => 'canceled', 'note' => 'Tempo limite do build esgotado.']);

            return new StepResult(124, 0, true);
        }
        $builds->updateStep($stepId, ['status' => 'running', 'started_at' => Time::now()]);
        $first = Text::firstLine($script);
        $log->system($stepId, "[vau] ▸ {$image} $ " . Text::truncate($first, 160) . (str_contains(trim($script), "\n") ? ' …' : ''));
        $result = $this->runtime->runStep($ctx, $position, $image, $script, $env, $remaining, fn(string $s, string $c) => $log->write($stepId, $s, $c), $shouldStop);
        $log->flush(true);
        if ($result->timedOut) {
            $log->system($stepId, '[vau] Tempo limite atingido; o container foi encerrado.');
        } elseif ($result->canceled) {
            $log->system($stepId, '[vau] Build cancelado; o container foi encerrado.');
        }
        $this->finishStep($stepId, $result);

        return $result;
    }

    private function finishStep(int $stepId, StepResult $result): void
    {
        $this->repos->builds()->updateStep($stepId, [
            'status' => $result->canceled || $result->timedOut ? 'canceled' : ($result->ok() ? 'success' : 'failed'),
            'exit_code' => $result->exitCode,
            'finished_at' => Time::now(),
            'duration_ms' => $result->durationMs,
            'note' => $result->timedOut ? 'Tempo limite atingido.' : ($result->canceled ? 'Cancelado.' : null),
        ]);
    }

    /** @return array{0: string, 1: string, 2: int} */
    private function failure(StepResult $result, string $message): array
    {
        if ($result->canceled) {
            return ['canceled', 'Build cancelado durante a execução.', $result->exitCode];
        }
        if ($result->timedOut) {
            return ['failed', 'Tempo limite do build atingido.', $result->exitCode];
        }

        return ['failed', $message . " Código {$result->exitCode}.", $result->exitCode];
    }

    private function skipRemaining(int $buildId, string $status): void
    {
        $this->db->run(
            "UPDATE va_build_step SET status = ?, note = COALESCE(note, ?) WHERE build_id = ? AND status IN ('pending', 'running')",
            [$status, $status === 'canceled' ? 'Cancelado.' : 'Não executada.', $buildId],
        );
    }

    /**
     * Variáveis disponíveis em toda etapa, no padrão de outros CIs.
     *
     * @param array<string, mixed> $project
     * @param array<string, mixed> $build
     * @return array<string, string>
     */
    private function baseEnv(array $project, array $build): array
    {
        return [
            'CI' => 'true',
            'VAU' => 'true',
            'VAU_VERSION' => App::VERSION,
            'VAU_BUILD_ID' => (string) $build['id'],
            'VAU_BUILD_NUMBER' => (string) $build['number'],
            'VAU_BUILD_URL' => $this->config->appUrl() . '/builds/' . $build['id'],
            'VAU_PROJECT' => (string) $project['slug'],
            'VAU_TRIGGER' => (string) $build['trigger_kind'],
            'VAU_REF' => (string) $build['git_ref'],
            'VAU_BRANCH' => (string) ($build['branch'] ?? ''),
            'VAU_TAG' => (string) ($build['tag'] ?? ''),
            'VAU_COMMIT' => (string) ($build['commit_sha'] ?? ''),
            'VAU_PR' => (string) ($build['pr_number'] ?? ''),
        ];
    }

    /**
     * @param array<string, array{value: string, deploy_only: bool}> $secrets
     * @return array<string, string>
     */
    private function secretEnv(array $secrets, bool $deploy): array
    {
        $out = [];
        foreach ($secrets as $name => $secret) {
            if ($deploy || !$secret['deploy_only']) {
                $out[$name] = $secret['value'];
            }
        }

        return $out;
    }
}
