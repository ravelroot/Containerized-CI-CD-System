<?php

declare(strict_types=1);

namespace Vau\Http;

/**
 * Roteador com parâmetros nomeados ({id}, {slug}) e restrições simples:
 * {id} só aceita dígitos; os demais aceitam qualquer trecho sem barra.
 */
final class Router
{
    /** @var list<array{method: string, regex: string, handler: array{0: class-string, 1: string}, options: array<string, mixed>}> */
    private array $routes = [];

    /** @param array<string, mixed> $options */
    public function add(string $method, string $pattern, array $handler, array $options = []): void
    {
        $regex = preg_replace_callback('/\{(\w+)\}/', static function (array $m): string {
            return $m[1] === 'id' ? '(?P<id>\d+)' : '(?P<' . $m[1] . '>[^/]+)';
        }, $pattern);
        $this->routes[] = ['method' => $method, 'regex' => '#^' . $regex . '$#', 'handler' => $handler, 'options' => $options];
    }

    /** @param array<string, mixed> $options */
    public function get(string $pattern, array $handler, array $options = []): void
    {
        $this->add('GET', $pattern, $handler, $options);
    }

    /** @param array<string, mixed> $options */
    public function post(string $pattern, array $handler, array $options = []): void
    {
        $this->add('POST', $pattern, $handler, $options);
    }

    /**
     * @return array{0: array{0: class-string, 1: string}, 1: array<string, string>, 2: array<string, mixed>}|int
     *         rota encontrada, ou 404/405
     */
    public function match(string $method, string $path): array|int
    {
        $allowed = false;
        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path, $m) !== 1) {
                continue;
            }
            if ($route['method'] !== $method && !($method === 'HEAD' && $route['method'] === 'GET')) {
                $allowed = true;
                continue;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);

            return [$route['handler'], $params, $route['options']];
        }

        return $allowed ? 405 : 404;
    }
}
