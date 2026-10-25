<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Vau\\Tests\\')) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen('Vau\\Tests\\'))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
