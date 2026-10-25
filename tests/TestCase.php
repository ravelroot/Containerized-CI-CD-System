<?php

declare(strict_types=1);

namespace Vau\Tests;

use Vau\App;
use Vau\Config;
use Vau\Support\Time;

/**
 * Base dos testes: cada teste ganha uma aplicação nova com SQLite em
 * memória, chave de criptografia própria e runtime falso (sem Docker).
 */
abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected App $app;
    protected FakeRuntime $runtime;

    protected function setUp(): void
    {
        parent::setUp();
        Time::freeze(null);
        $root = dirname(__DIR__);
        $config = Config::fromArray([
            'VAU_DATABASE_URL' => 'sqlite::memory:',
            'VAU_APP_KEY' => 'base64:' . base64_encode(str_repeat('k', 32)),
            'VAU_APP_URL' => 'https://ci.exemplo.test',
            'VAU_LOG_FILE' => sys_get_temp_dir() . '/vau-testes.log',
            'VAU_RUNTIME' => 'docker',
        ], $root);
        $this->app = App::boot($root, $config);
        $this->runtime = new FakeRuntime();
        $runtime = $this->runtime;
        $this->app->override(\Vau\Runner\ContainerRuntime::class, static fn() => $runtime);
        $http = new FakeHttp();
        $this->app->override(\Vau\Integrations\HttpClient::class, static fn() => $http);
    }

    /** @return array<string, mixed> */
    protected function project(array $overrides = []): array
    {
        $created = $this->app->repos()->projects()->create($overrides + [
            'name' => 'Loja',
            'provider' => 'github',
            'repo_url' => 'https://github.com/acme/loja.git',
            'default_branch' => 'main',
            'events' => ['push', 'pull_request', 'tag'],
            'auto_cancel' => 1,
            'allow_deploy' => 1,
            'report_status' => 0,
        ], null);

        return $this->app->repos()->projects()->find($created['id']) + ['_secret' => $created['secret']];
    }

    /** @return array<string, mixed> */
    protected static function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/fixtures/' . $name), true, 64, JSON_THROW_ON_ERROR);
    }
}
