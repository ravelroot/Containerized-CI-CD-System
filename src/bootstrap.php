<?php

declare(strict_types=1);

/*
 * Ponto de partida comum ao painel, ao worker e à linha de comando.
 * Funciona com ou sem Composer: se vendor/autoload.php não existir,
 * um carregador PSR-4 mínimo resolve o namespace Vau\ em src/.
 */

if (version_compare(PHP_VERSION, '8.2.0', '<')) {
    fwrite(STDERR, "O Vau precisa do PHP 8.2 ou mais recente.\n");
    exit(1);
}

$root = dirname(__DIR__);

if (is_file($root . '/vendor/autoload.php')) {
    require $root . '/vendor/autoload.php';
}

spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'Vau\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = $root . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

mb_internal_encoding('UTF-8');
error_reporting(E_ALL);
ini_set('display_errors', '0');

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

return Vau\App::boot($root);
