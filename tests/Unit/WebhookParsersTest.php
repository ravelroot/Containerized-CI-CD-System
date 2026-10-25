<?php

declare(strict_types=1);

namespace Vau\Tests\Unit;

use Vau\Tests\TestCase;
use Vau\Webhook\GitHubParser;
use Vau\Webhook\GitLabParser;
use Vau\Webhook\IgnoredEvent;

final class WebhookParsersTest extends TestCase
{
    public function testGitHubSignature(): void
    {
        $body = '{"a":1}';
        $sig = 'sha256=' . hash_hmac('sha256', $body, 'segredo');

        self::assertTrue(GitHubParser::verify($body, 'segredo', $sig));
        self::assertFalse(GitHubParser::verify($body, 'outro', $sig));
        self::assertFalse(GitHubParser::verify($body . ' ', 'segredo', $sig));
        self::assertFalse(GitHubParser::verify($body, 'segredo', null));
        self::assertFalse(GitHubParser::verify($body, '', 'sha256='));
    }

    public function testGitHubPush(): void
    {
        $e = GitHubParser::parse('push', self::fixture('github-push.json'));

        self::assertSame('push', $e->kind);
        self::assertSame('main', $e->branch);
        self::assertSame('refs/heads/main', $e->ref);
        self::assertSame('a10867b14bb761a232cd80139fbd4c0d33264240', $e->sha);
        self::assertSame('marinacosta', $e->author);
        self::assertStringStartsWith('Corrige cálculo do frete', (string) $e->message);
    }

    public function testGitHubTagPush(): void
    {
        $payload = self::fixture('github-push.json');
        $payload['ref'] = 'refs/tags/v2.0.0';
        $e = GitHubParser::parse('push', $payload);

        self::assertSame('tag', $e->kind);
        self::assertSame('v2.0.0', $e->tag);
        self::assertNull($e->branch);
    }

    public function testGitHubPullRequestFromFork(): void
    {
        $e = GitHubParser::parse('pull_request', self::fixture('github-pull-request.json'));

        self::assertSame('pull_request', $e->kind);
        self::assertSame(42, $e->prNumber);
        self::assertSame('refs/pull/42/head', $e->ref);
        self::assertSame('feature/checkout', $e->branch);
        self::assertTrue($e->isFork);
    }

    public function testGitHubIgnoresClosedPullRequestAndDeletedBranch(): void
    {
        $pr = self::fixture('github-pull-request.json');
        $pr['action'] = 'closed';
        try {
            GitHubParser::parse('pull_request', $pr);
            self::fail('PR fechado deveria ser ignorado');
        } catch (IgnoredEvent) {
            self::addToAssertionCount(1);
        }
        $push = self::fixture('github-push.json');
        $push['deleted'] = true;
        $this->expectException(IgnoredEvent::class);
        GitHubParser::parse('push', $push);
    }

    public function testGitLabPushAndToken(): void
    {
        self::assertTrue(GitLabParser::verify('abc', 'abc'));
        self::assertFalse(GitLabParser::verify('abc', 'abd'));

        $e = GitLabParser::parse('Push Hook', self::fixture('gitlab-push.json'));
        self::assertSame('develop', $e->branch);
        self::assertSame('Júlia Andrade', $e->author);
        self::assertSame('Ajusta layout do carrinho', $e->message);
    }

    public function testGitLabMergeRequest(): void
    {
        $e = GitLabParser::parse('Merge Request Hook', self::fixture('gitlab-merge-request.json'));

        self::assertSame('pull_request', $e->kind);
        self::assertSame(7, $e->prNumber);
        self::assertSame('refs/merge-requests/7/head', $e->ref);
        self::assertFalse($e->isFork);
    }

    public function testGitLabIgnoresUpdateWithoutNewCommits(): void
    {
        $mr = self::fixture('gitlab-merge-request.json');
        $mr['object_attributes']['action'] = 'update';
        $this->expectException(IgnoredEvent::class);
        GitLabParser::parse('Merge Request Hook', $mr);
    }
}
