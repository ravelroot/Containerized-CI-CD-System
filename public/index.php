<?php

declare(strict_types=1);

/*
 * Front controller do painel, da API e dos webhooks.
 * No servidor embutido do PHP, arquivos de public/assets são servidos direto.
 */

if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($file) && str_starts_with(realpath($file) ?: '', __DIR__ . DIRECTORY_SEPARATOR . 'assets')) {
        return false;
    }
}

$app = require dirname(__DIR__) . '/src/bootstrap.php';

(new Vau\Http\Kernel($app))->handle(Vau\Http\Request::fromGlobals())->send();
