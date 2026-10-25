<?php

declare(strict_types=1);

namespace Vau\Support;

/**
 * Datas são gravadas em UTC no formato "Y-m-d H:i:s" em qualquer banco
 * e convertidas para o fuso configurado só na hora de exibir.
 */
final class Time
{
    private static ?int $frozen = null;

    public static function freeze(?int $timestamp): void
    {
        self::$frozen = $timestamp;
    }

    public static function ts(): int
    {
        return self::$frozen ?? time();
    }

    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s', self::ts());
    }

    public static function at(int $timestamp): string
    {
        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    public static function toTs(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', substr($value, 0, 19), new \DateTimeZone('UTC'));

        return $dt === false ? null : $dt->getTimestamp();
    }

    public static function local(?string $value, \DateTimeZone $tz, string $format = 'd/m/Y H:i'): string
    {
        $ts = self::toTs($value);
        if ($ts === null) {
            return '—';
        }

        return (new \DateTimeImmutable('@' . $ts))->setTimezone($tz)->format($format);
    }

    public static function ago(?string $value): string
    {
        $ts = self::toTs($value);
        if ($ts === null) {
            return '—';
        }
        $diff = max(0, self::ts() - $ts);

        return match (true) {
            $diff < 45 => 'agora',
            $diff < 3600 => 'há ' . max(1, (int) round($diff / 60)) . ' min',
            $diff < 86400 => 'há ' . (int) round($diff / 3600) . ' h',
            $diff < 86400 * 30 => 'há ' . (int) round($diff / 86400) . ' d',
            default => gmdate('d/m/Y', $ts),
        };
    }
}
