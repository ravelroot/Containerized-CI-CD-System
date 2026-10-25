<?php

declare(strict_types=1);

namespace Vau\Http;

/**
 * Gráficos em SVG gerados no servidor: nada de biblioteca JavaScript, o
 * painel abre rápido e funciona até com scripts bloqueados.
 */
final class Charts
{
    /**
     * Barras empilhadas de builds por dia (sucesso embaixo, falha em cima).
     *
     * @param list<array{label: string, success: int, failed: int, other: int}> $series
     */
    public static function dailyBars(array $series, int $width = 640, int $height = 168): string
    {
        $max = 1;
        foreach ($series as $point) {
            $max = max($max, $point['success'] + $point['failed'] + $point['other']);
        }
        $count = max(1, count($series));
        $padBottom = 22;
        $padTop = 18;
        $plot = $height - $padBottom - $padTop;
        $out = sprintf('<svg class="chart" viewBox="0 0 %d %d" role="img" aria-label="Builds por dia">', $width, $height);
        $width -= 22; // espaço para os rótulos do eixo
        $slot = $width / $count;
        $bar = max(4, min(26, $slot * 0.56));
        foreach ([0.5, 1] as $g) {
            $y = $padTop + $plot - $plot * $g;
            $out .= sprintf('<line x1="0" x2="%d" y1="%.1f" y2="%.1f" class="chart__grid"/>', $width, $y, $y);
            $out .= sprintf('<text x="%d" y="%.1f" class="chart__axis" text-anchor="start">%d</text>', $width + 6, $y + 4, (int) round($max * $g));
        }
        foreach ($series as $i => $point) {
            $x = $slot * $i + ($slot - $bar) / 2;
            $base = $padTop + $plot;
            $title = sprintf('%s: %d sucesso, %d falha, %d outros', $point['label'], $point['success'], $point['failed'], $point['other']);
            $out .= '<g><title>' . htmlspecialchars($title, ENT_QUOTES) . '</title>';
            foreach (['success' => 'chart__ok', 'failed' => 'chart__bad', 'other' => 'chart__other'] as $key => $class) {
                if ($point[$key] <= 0) {
                    continue;
                }
                $h = $plot * $point[$key] / $max;
                $base -= $h;
                $out .= sprintf('<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" rx="2" class="%s"/>', $x, $base, $bar, max(1.5, $h - 1.5), $class);
            }
            if ($point['success'] + $point['failed'] + $point['other'] === 0) {
                $out .= sprintf('<rect x="%.1f" y="%.1f" width="%.1f" height="2" rx="1" class="chart__empty"/>', $x, $padTop + $plot - 2, $bar);
            }
            $out .= '</g>';
            if ($count <= 14 || $i % 2 === 0) {
                $out .= sprintf('<text x="%.1f" y="%d" class="chart__axis" text-anchor="middle">%s</text>', $x + $bar / 2, $height - 4, htmlspecialchars($point['label'], ENT_QUOTES));
            }
        }

        return $out . '</svg>';
    }

    /**
     * Faixa com a duração dos últimos builds de um projeto (cada barra é um build).
     *
     * @param list<array{status: string, duration_ms: int|null}> $builds do mais antigo ao mais novo
     */
    public static function durationStrip(array $builds, int $width = 220, int $height = 36): string
    {
        $max = 1;
        foreach ($builds as $b) {
            $max = max($max, (int) ($b['duration_ms'] ?? 0));
        }
        $slots = 20;
        $slot = $width / $slots;
        $out = sprintf('<svg class="strip" viewBox="0 0 %d %d" aria-hidden="true">', $width, $height);
        $offset = $slots - count($builds);
        foreach ($builds as $i => $b) {
            $h = max(3, ($height - 2) * ((int) ($b['duration_ms'] ?? 0)) / $max);
            $class = match ($b['status']) {
                'success' => 'chart__ok',
                'failed', 'error' => 'chart__bad',
                default => 'chart__other',
            };
            $out .= sprintf('<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" rx="1.5" class="%s"/>', ($offset + $i) * $slot + 1, $height - $h, $slot - 3, $h, $class);
        }

        return $out . '</svg>';
    }

    /** Selo SVG de status para o README do repositório. */
    public static function badge(string $label, string $status): string
    {
        [$text, $color] = match ($status) {
            'success' => ['passou', '#3e6b2a'],
            'failed' => ['falhou', '#9b2f1e'],
            'error' => ['erro', '#9b2f1e'],
            'running' => ['rodando', '#2b4c5a'],
            'queued' => ['na fila', '#7a5a12'],
            'canceled' => ['cancelado', '#6b6969'],
            default => ['sem builds', '#6b6969'],
        };
        $lw = 6 * mb_strlen($label) + 14;
        $rw = 6 * mb_strlen($text) + 14;
        $w = $lw + $rw;
        $l = htmlspecialchars($label, ENT_QUOTES);

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="{$w}" height="20" role="img" aria-label="{$l}: {$text}"><title>{$l}: {$text}</title><rect width="{$w}" height="20" rx="4" fill="#000"/><rect x="{$lw}" width="{$rw}" height="20" rx="4" fill="{$color}"/><rect x="{$lw}" width="4" height="20" fill="{$color}"/><g fill="#fff" font-family="Verdana,DejaVu Sans,sans-serif" font-size="11"><text x="7" y="14">{$l}</text><text x="{$lw}" dx="7" y="14">{$text}</text></g></svg>
SVG;
    }
}
