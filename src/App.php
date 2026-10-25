<?php

declare(strict_types=1);

namespace Vau;

use Vau\Auth\Auth;
use Vau\Build\BuildQueue;
use Vau\Database\Connection;
use Vau\Database\Repositories;
use Vau\Database\Schema;
use Vau\Integrations\HttpClient;
use Vau\Integrations\Notifier;
use Vau\Integrations\StatusReporter;
use Vau\Runner\BuildRunner;
use Vau\Runner\ContainerRuntime;
use Vau\Runner\DockerRuntime;
use Vau\Runner\LocalRuntime;
use Vau\Support\Crypto;
use Vau\Support\Logger;

/**
 * Contêiner de serviços da aplicação. Cada serviço é criado sob demanda
 * uma única vez por processo, o que mantém o painel leve: uma requisição
 * que só lê builds nunca instancia o runtime Docker.
 */
final class App
{
    public const VERSION = '1.0.0';
    public const NAME = 'Vau';

    private static ?self $instance = null;

    /** @var array<string, object> */
    private array $services = [];

    /** @var array<string, callable(self): object> */
    private array $overrides = [];

    private function __construct(public readonly string $root, public readonly Config $config) {}

    public static function boot(string $root, ?Config $config = null): self
    {
        $config ??= Config::load($root);
        date_default_timezone_set('UTC');
        self::$instance = new self($root, $config);

        return self::$instance;
    }

    public static function get(): self
    {
        if (self::$instance === null) {
            throw new \LogicException('A aplicação ainda não foi iniciada.');
        }

        return self::$instance;
    }

    /**
     * Substitui um serviço (usado pelos testes para trocar o runtime ou o HTTP).
     *
     * @param callable(self): object $factory
     */
    public function override(string $id, callable $factory): void
    {
        $this->overrides[$id] = $factory;
        unset($this->services[$id]);
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @param callable(self): T $factory
     * @return T
     */
    private function shared(string $id, callable $factory): object
    {
        if (!isset($this->services[$id])) {
            $make = $this->overrides[$id] ?? $factory;
            $this->services[$id] = $make($this);
        }
        /** @var T */
        return $this->services[$id];
    }

    public function db(): Connection
    {
        return $this->shared(Connection::class, function (self $app): Connection {
            $db = Connection::fromUrl($app->config->databaseUrl(), $app->root);
            if ($app->config->bool('VAU_AUTO_MIGRATE', true)) {
                (new Schema($db))->migrate();
            }

            return $db;
        });
    }

    public function repos(): Repositories
    {
        return $this->shared(Repositories::class, fn(self $app) => new Repositories($app->db(), $app->crypto()));
    }

    public function crypto(): Crypto
    {
        return $this->shared(Crypto::class, fn(self $app) => Crypto::fromConfig($app->config, $app->root));
    }

    public function logger(): Logger
    {
        return $this->shared(Logger::class, fn(self $app) => new Logger(
            $app->config->path('VAU_LOG_FILE', 'storage/logs/vau.log'),
            $app->config->get('VAU_LOG_LEVEL', 'info'),
        ));
    }

    public function auth(): Auth
    {
        return $this->shared(Auth::class, fn(self $app) => new Auth($app->repos(), $app->config));
    }

    public function queue(): BuildQueue
    {
        return $this->shared(BuildQueue::class, fn(self $app) => new BuildQueue($app->db(), $app->repos()));
    }

    public function runtime(): ContainerRuntime
    {
        return $this->shared(ContainerRuntime::class, function (self $app): ContainerRuntime {
            return match ($app->config->runtime()) {
                'local' => new LocalRuntime($app->config),
                default => new DockerRuntime($app->config),
            };
        });
    }

    public function http(): HttpClient
    {
        return $this->shared(HttpClient::class, fn() => new HttpClient());
    }

    public function runner(): BuildRunner
    {
        return $this->shared(BuildRunner::class, fn(self $app) => new BuildRunner(
            $app->db(),
            $app->repos(),
            $app->runtime(),
            $app->config,
            $app->logger(),
            new StatusReporter($app->http(), $app->config, $app->logger()),
            new Notifier($app->http(), $app->config, $app->logger()),
        ));
    }
}
