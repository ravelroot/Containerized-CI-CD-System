<?php

declare(strict_types=1);

namespace Vau\Support;

/**
 * Logger em JSON Lines (uma linha JSON por evento), compatível com
 * Loki, Elastic, CloudWatch e qualquer coletor que leia arquivos.
 */
final class Logger
{
    private const LEVELS = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3, 'critical' => 4];

    private int $threshold;

    public function __construct(private readonly string $file, string $level = 'info', private readonly bool $stderr = false)
    {
        $this->threshold = self::LEVELS[strtolower($level)] ?? 1;
    }

    /** @param array<string, mixed> $context */
    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function log(string $level, string $message, array $context = []): void
    {
        if ((self::LEVELS[$level] ?? 1) < $this->threshold) {
            return;
        }
        foreach ($context as $key => $value) {
            if ($value instanceof \Throwable) {
                $context[$key] = [
                    'class' => $value::class,
                    'message' => $value->getMessage(),
                    'file' => $value->getFile() . ':' . $value->getLine(),
                ];
            }
        }
        $line = json_encode([
            'ts' => gmdate('Y-m-d\TH:i:s\Z'),
            'level' => $level,
            'message' => $message,
            'context' => $context ?: new \stdClass(),
            'pid' => getmypid(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($this->stderr) {
            fwrite(STDERR, $line . PHP_EOL);
        }
        if ($this->file === '') {
            return;
        }
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($this->file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
