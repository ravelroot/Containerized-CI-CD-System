<?php

declare(strict_types=1);

namespace Vau\Support;

/**
 * Funções de texto e formatação usadas pelo painel, pela API e pelo worker.
 */
final class Text
{
    public static function slug(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $value = $ascii !== false ? $ascii : $value;
        $value = (string) preg_replace('/[^a-z0-9]+/', '-', $value);

        return trim(substr($value, 0, 60), '-');
    }

    public static function shortSha(?string $sha): string
    {
        return $sha ? substr($sha, 0, 7) : '—';
    }

    public static function duration(?int $ms): string
    {
        if ($ms === null) {
            return '—';
        }
        $seconds = intdiv($ms, 1000);
        if ($seconds < 1) {
            return number_format($ms / 1000, 1, ',', '') . ' s';
        }
        if ($seconds < 60) {
            return $seconds . ' s';
        }
        $minutes = intdiv($seconds, 60);
        if ($minutes < 60) {
            return sprintf('%d min %02d s', $minutes, $seconds % 60);
        }

        return sprintf('%d h %02d min', intdiv($minutes, 60), $minutes % 60);
    }

    public static function bytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $value = (float) $bytes;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return ($i === 0 ? (string) $bytes : number_format($value, 1, ',', '.')) . ' ' . $units[$i];
    }

    public static function truncate(?string $value, int $limit): string
    {
        $value = (string) $value;

        return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit - 1) . '…' : $value;
    }

    public static function firstLine(?string $value): string
    {
        $value = trim((string) $value);
        $pos = strpos($value, "\n");

        return $pos === false ? $value : rtrim(substr($value, 0, $pos));
    }

    /**
     * Remove sequências ANSI (cores e cursor) que ferramentas de teste
     * imprimem, para guardar e exibir o log como texto limpo.
     */
    public static function stripAnsi(string $value): string
    {
        return (string) preg_replace('/\x1B(?:\[[0-?]*[ -\/]*[@-~]|\][^\x07]*\x07|[@-Z\\\\-_])/', '', $value);
    }

    /**
     * Verifica se um nome (branch ou tag) bate com uma lista de padrões
     * separados por vírgula, com curingas no estilo shell: main, release/*.
     * Lista vazia aceita tudo. Um padrão iniciado por ! exclui.
     */
    public static function matchesPatterns(?string $name, string|array $patterns): bool
    {
        $list = is_array($patterns) ? $patterns : explode(',', $patterns);
        $list = array_values(array_filter(array_map(static fn($p) => trim((string) $p), $list), static fn($p) => $p !== ''));
        if ($list === []) {
            return true;
        }
        if ($name === null || $name === '') {
            return false;
        }
        $included = false;
        $hasInclude = false;
        foreach ($list as $pattern) {
            if ($pattern[0] === '!') {
                if (fnmatch(substr($pattern, 1), $name)) {
                    return false;
                }
                continue;
            }
            $hasInclude = true;
            if (fnmatch($pattern, $name)) {
                $included = true;
            }
        }

        return $included || !$hasInclude;
    }

    public static function maskUrlCredentials(string $url): string
    {
        return (string) preg_replace('#(https?://)[^/@\s]+@#', '$1•••@', $url);
    }
}
