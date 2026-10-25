<?php

declare(strict_types=1);

namespace Vau\Tests\Integration;

use Vau\Tests\TestCase;
use Vau\Webhook\WebhookEvent;
use Vau\Webhook\WebhookHandler;

/** Do webhook ao resultado do build, com banco real (SQLite em memória). */
final class BuildFlowTest extends TestCase
{
    private function handler(): WebhookHandler
    {
        return new WebhookHandler($this->app->repos(), $this->app->queue(), $this->app->logger(), 1_048_576);
    }

    /** @return array<string, string> */
    private function githubHeaders(string $body, string $secret, string $event = 'push', string $delivery = 'd-1'): array
    {
        return [
            'x-github-event' => $event,
            'x-github-delivery' => $delivery,
            'x-hub-signature-256' => 'sha256=' . hash_hmac('sha256', $body, $secret),
            'content-type' => 'application/json',
        ];
    }

    public function testSignedPushQueuesBuildAndDuplicateIsIgnored(): void
    {
        $project = $this->project();
        $body = (string) file_get_contents(__DIR__ . '/../fixtures/github-push.json');

        $first = $this->handler()->handle('loja', $this->githubHeaders($body, $project['_secret']), $body, '10.0.0.1');
        self::assertSame(202, $first->status);
        self::assertSame(1, $first->buildNumber);

        $again = $this->handler()->handle('loja', $this->githubHeaders($body, $project['_secret']), $body, '10.0.0.1');
        self::assertSame(200, $again->status);
        self::assertSame(1, $this->app->repos()->builds()->count());
    }

    public function testBadSignatureUnknownProjectAndFilters(): void
    {
        $project = $this->project(['branch_filter' => 'release/*']);
        $body = (string) file_get_contents(__DIR__ . '/../fixtures/github-push.json');

        self::assertSame(401, $this->handler()->handle('loja', $this->githubHeaders($body, 'errado'), $body, null)->status);
        self::assertSame(404, $this->handler()->handle('nao-existe', $this->githubHeaders($body, 'x'), $body, null)->status);
        $filtered = $this->handler()->handle('loja', $this->githubHeaders($body, $project['_secret'], 'push', 'd-2'), $body, null);
        self::assertSame(200, $filtered->status);
        self::assertStringContainsString('filtro', $filtered->message);
        self::assertSame(0, $this->app->repos()->builds()->count());
        self::assertCount(3, $this->app->repos()->deliveries()->recent());
    }

    public function testGitLabTokenAndMergeRequest(): void
    {
        $project = $this->project(['provider' => 'gitlab', 'repo_url' => 'https://gitlab.com/acme/loja.git']);
        $body = (string) file_get_contents(__DIR__ . '/../fixtures/gitlab-merge-request.json');
        $result = $this->handler()->handle('loja', [
            'x-gitlab-event' => 'Merge Request Hook',
            'x-gitlab-token' => $project['_secret'],
        ], $body, null);

        self::assertSame(202, $result->status);
        $build = $this->app->repos()->builds()->find((int) $result->buildId);
        self::assertSame('refs/merge-requests/7/head', $build['git_ref']);
        self::assertSame(7, (int) $build['pr_number']);
    }

    public function testAutoCancelSupersedesQueuedBuildsOfSameBranch(): void
    {
        $project = $this->project();
        $queue = $this->app->queue();
        $old = $queue->enqueueManual($project, 'main', null, null);
        $other = $queue->enqueueManual($project, 'develop', null, null);
        $event = new WebhookEvent('push', 'push', 'refs/heads/main', 'main', null, str_repeat('a', 40));
        $new = $queue->enqueue($project, $event, 'push');

        self::assertSame(1, $new['canceled']);
        self::assertSame('canceled', $this->app->repos()->builds()->find($old['id'])['status']);
        self::assertSame('queued', $this->app->repos()->builds()->find($other['id'])['status']);
        self::assertSame(3, $new['number']);
    }

    public function testClaimIsExclusive(): void
    {
        $project = $this->project(['auto_cancel' => 0]);
        $queue = $this->app->queue();
        $a = $queue->enqueueManual($project, 'main', null, null);
        $b = $queue->enqueueManual($project, 'main', null, null);

        self::assertSame($a['id'], $queue->claim('w1'));
        self::assertSame($b['id'], $queue->claim('w2'));
        self::assertNull($queue->claim('w3'));
    }

    public function testSuccessfulBuildRunsStepsAndDeployWithMaskedSecrets(): void
    {
        $project = $this->project();
        $repos = $this->app->repos();
        $repos->projects()->putSecret((int) $project['id'], 'API_KEY', 'chave-super-secreta', false);
        $repos->projects()->putSecret((int) $project['id'], 'DEPLOY_TOKEN', 'token-de-deploy', true);
        $this->runtime->pipeline = "steps:\n  - name: Testes\n    run: make test\ndeploy:\n  branches: [main]\n  run: make deploy\n";
        $this->runtime->outputs['make test'] = "usando chave-super-secreta\nok\n";

        $build = $this->app->queue()->enqueueManual($project, 'main', null, null);
        $this->app->queue()->claim('w1');
        $status = $this->app->runner()->run($build['id']);

        self::assertSame('success', $status);
        self::assertTrue($this->runtime->cleaned);
        self::assertCount(2, $this->runtime->calls);
        self::assertSame('chave-super-secreta', $this->runtime->calls[0]['env']['API_KEY']);
        self::assertArrayNotHasKey('DEPLOY_TOKEN', $this->runtime->calls[0]['env'], 'segredo de deploy não vai para os testes');
        self::assertSame('token-de-deploy', $this->runtime->calls[1]['env']['DEPLOY_TOKEN']);
        self::assertSame('main', $this->runtime->calls[0]['env']['VAU_BRANCH']);

        $log = $repos->builds()->fullLog($build['id']);
        self::assertStringNotContainsString('chave-super-secreta', $log);
        self::assertStringContainsString('usando ••••••', $log);

        $saved = $repos->builds()->find($build['id']);
        self::assertSame(1, (int) $saved['is_deploy']);
        $steps = array_column($repos->builds()->steps($build['id']), 'status', 'name');
        self::assertSame(['Clonar repositório' => 'success', 'Testes' => 'success', 'Deploy' => 'success'], $steps);
    }

    public function testFailedStepSkipsTheRestAndDeploy(): void
    {
        $project = $this->project();
        $this->runtime->pipeline = "steps:\n  - run: lint\n  - run: test\n  - run: build\ndeploy:\n  run: ship\n";
        $this->runtime->exitCodes['test'] = 2;

        $build = $this->app->queue()->enqueueManual($project, 'main', null, null);
        $status = $this->app->runner()->run($build['id']);

        self::assertSame('failed', $status);
        self::assertSame(['lint', 'test'], array_column($this->runtime->calls, 'script'));
        $steps = array_column($this->app->repos()->builds()->steps($build['id']), 'status');
        self::assertSame(['success', 'success', 'failed', 'skipped', 'skipped'], $steps);
        self::assertStringContainsString('código 2', (string) $this->app->repos()->builds()->find($build['id'])['error_message']);
    }

    public function testPullRequestFromForkGetsNoSecretsAndNoDeploy(): void
    {
        $project = $this->project();
        $this->app->repos()->projects()->putSecret((int) $project['id'], 'API_KEY', 'valor-secreto', false);
        $this->runtime->pipeline = "steps:\n  - run: test\ndeploy:\n  branches: ['*']\n  run: ship\n";
        $event = new WebhookEvent('pull_request', 'pull_request', 'refs/pull/9/head', 'feature/x', null, str_repeat('b', 40), prNumber: 9, isFork: true);
        $build = $this->app->queue()->enqueue($project, $event, 'pull_request');

        self::assertSame('success', $this->app->runner()->run($build['id']));
        self::assertCount(1, $this->runtime->calls);
        self::assertArrayNotHasKey('API_KEY', $this->runtime->calls[0]['env']);
    }

    public function testMissingPipelineAndCloneFailure(): void
    {
        $project = $this->project();
        $this->runtime->pipeline = null;
        $build = $this->app->queue()->enqueueManual($project, 'main', null, null);
        self::assertSame('error', $this->app->runner()->run($build['id']));
        self::assertStringContainsString('Nenhum pipeline', (string) $this->app->repos()->builds()->find($build['id'])['error_message']);

        $this->runtime->cloneExit = 128;
        $build2 = $this->app->queue()->enqueueManual($project, 'develop', null, null);
        self::assertSame('failed', $this->app->runner()->run($build2['id']));
    }

    public function testProjectFallbackPipelineIsUsed(): void
    {
        $project = $this->project(['pipeline_yaml' => "steps:\n  - run: padrao\n"]);
        $this->runtime->pipeline = null;
        $build = $this->app->queue()->enqueueManual($project, 'main', null, null);

        self::assertSame('success', $this->app->runner()->run($build['id']));
        self::assertSame('padrao', $this->runtime->calls[0]['script']);
        self::assertSame('padrão do projeto', $this->app->repos()->builds()->find($build['id'])['pipeline_file']);
    }

    public function testCancelRunningBuild(): void
    {
        $project = $this->project();
        $this->runtime->cancelOnStep = true;
        $build = $this->app->queue()->enqueueManual($project, 'main', null, null);
        $this->app->queue()->claim('w1');
        self::assertSame('requested', $this->app->queue()->cancel($build['id']));

        self::assertSame('canceled', $this->app->runner()->run($build['id']));
    }

    public function testRetriedPullRequestNeverDeploys(): void
    {
        $project = $this->project();
        $this->runtime->pipeline = "steps:\n  - run: test\ndeploy:\n  branches: ['*']\n  run: ship\n";
        $event = new WebhookEvent('pull_request', 'pull_request', 'refs/pull/5/head', 'feature/y', null, str_repeat('d', 40), prNumber: 5);
        $first = $this->app->queue()->enqueue($project, $event, 'pull_request');
        $retry = $this->app->queue()->retry($first['id'], null);

        self::assertSame('success', $this->app->runner()->run($retry['id']));
        self::assertSame(['test'], array_column($this->runtime->calls, 'script'));
        self::assertSame(0, (int) $this->app->repos()->builds()->find($retry['id'])['is_deploy']);
    }

    public function testRetryCopiesParameters(): void
    {
        $project = $this->project();
        $first = $this->app->queue()->enqueueManual($project, 'develop', 'abcdef1', null);
        $retry = $this->app->queue()->retry($first['id'], null);
        $saved = $this->app->repos()->builds()->find($retry['id']);

        self::assertSame('develop', $saved['branch']);
        self::assertSame('abcdef1', $saved['commit_sha']);
        self::assertSame($first['id'], (int) $saved['retry_of']);
        self::assertSame('retry', $saved['trigger_kind']);
    }
}
