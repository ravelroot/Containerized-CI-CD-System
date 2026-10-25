<?php

declare(strict_types=1);

namespace Vau\Http;

use Vau\App;
use Vau\Auth\Auth;
use Vau\Repository\BuildRepository;
use Vau\Support\Text;
use Vau\Support\Time;

/**
 * Renderiza os templates PHP de templates/. Todo texto vindo do banco ou
 * do usuário passa por e() (htmlspecialchars), inclusive dentro de
 * atributos. O layout recebe o conteúdo da página em $content.
 */
final class View
{
    /** @var array<string, mixed> */
    private array $shared = [];

    public function __construct(private readonly string $dir, private readonly \DateTimeZone $tz) {}

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    /** @param array<string, mixed> $vars */
    public function render(string $template, array $vars = [], ?string $layout = 'layout'): string
    {
        $content = $this->partial($template, $vars);
        if ($layout === null) {
            return $content;
        }

        return $this->partial($layout, $vars + ['content' => $content]);
    }

    /** @param array<string, mixed> $vars */
    public function partial(string $template, array $vars = []): string
    {
        $file = $this->dir . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("Template {$template} não encontrado.");
        }
        $v = $this;
        extract($this->shared + $vars, EXTR_SKIP);
        ob_start();
        try {
            include $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (string) ob_get_clean();
    }

    public function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function date(?string $value, string $format = 'd/m/Y H:i'): string
    {
        return Time::local($value, $this->tz, $format);
    }

    public function ago(?string $value): string
    {
        return Time::ago($value);
    }

    public function duration(mixed $ms): string
    {
        return Text::duration($ms === null ? null : (int) $ms);
    }

    public function sha(?string $sha): string
    {
        return Text::shortSha($sha);
    }

    public function status(string $status): string
    {
        return BuildRepository::STATUS[$status] ?? BuildRepository::STEP_STATUS[$status] ?? $status;
    }

    public function trigger(string $trigger): string
    {
        return BuildRepository::TRIGGERS[$trigger] ?? $trigger;
    }

    public function truncate(?string $value, int $limit): string
    {
        return Text::truncate(Text::firstLine($value), $limit);
    }

    public function bytes(int $bytes): string
    {
        return Text::bytes($bytes);
    }

    public function csrf(): string
    {
        $token = $this->shared['csrf'] ?? '';

        return '<input type="hidden" name="_csrf" value="' . $this->e($token) . '">';
    }

    public function can(string $permission): bool
    {
        return Auth::allows($this->shared['user'] ?? null, $permission);
    }

    public function appUrl(): string
    {
        return App::get()->config->appUrl();
    }

    /** Ícones de traço fino (24×24) desenhados para o painel. */
    public function icon(string $name, int $size = 18): string
    {
        $paths = [
            'painel' => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
            'projetos' => '<path d="M3 7.5A1.5 1.5 0 0 1 4.5 6H9l2 2h8.5A1.5 1.5 0 0 1 21 9.5v8a1.5 1.5 0 0 1-1.5 1.5h-15A1.5 1.5 0 0 1 3 17.5z"/>',
            'builds' => '<path d="M4 6h16M4 12h10M4 18h7"/><circle cx="18" cy="16" r="3"/>',
            'entregas' => '<path d="M4 12h9M10 7l5 5-5 5"/><path d="M15 4h3.5A1.5 1.5 0 0 1 20 5.5v13a1.5 1.5 0 0 1-1.5 1.5H15"/>',
            'sistema' => '<path d="M4 7h10M18 7h2M4 17h4M12 17h8"/><circle cx="16" cy="7" r="2"/><circle cx="10" cy="17" r="2"/>',
            'lua' => '<path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5z"/>',
            'sol' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
            'sair' => '<path d="M10 4H5.5A1.5 1.5 0 0 0 4 5.5v13A1.5 1.5 0 0 0 5.5 20H10"/><path d="M14 8l4 4-4 4M18 12H9"/>',
            'mais' => '<path d="M12 5v14M5 12h14"/>',
            'play' => '<path d="M7 5l12 7-12 7z"/>',
            'repetir' => '<path d="M4 12a8 8 0 0 1 14-5.3L20 9"/><path d="M20 4v5h-5"/><path d="M20 12a8 8 0 0 1-14 5.3L4 15"/><path d="M4 20v-5h5"/>',
            'parar' => '<rect x="6" y="6" width="12" height="12" rx="1.5"/>',
            'copiar' => '<rect x="8" y="8" width="12" height="12" rx="1.5"/><path d="M16 8V5.5A1.5 1.5 0 0 0 14.5 4h-9A1.5 1.5 0 0 0 4 5.5v9A1.5 1.5 0 0 0 5.5 16H8"/>',
            'baixar' => '<path d="M12 4v11M7 10l5 5 5-5M5 20h14"/>',
            'externo' => '<path d="M14 4h6v6M20 4l-9 9"/><path d="M18 14v4.5a1.5 1.5 0 0 1-1.5 1.5h-11A1.5 1.5 0 0 1 4 18.5v-11A1.5 1.5 0 0 1 5.5 6H10"/>',
            'branch' => '<circle cx="6" cy="5" r="2"/><circle cx="6" cy="19" r="2"/><circle cx="18" cy="7" r="2"/><path d="M6 7v10M18 9c0 5-6 4-11 8"/>',
            'commit' => '<circle cx="12" cy="12" r="3.5"/><path d="M3 12h5.5M15.5 12H21"/>',
            'relogio' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
            'chave' => '<circle cx="8" cy="15" r="4"/><path d="M11 12l8-8M16 7l2 2M14 9l2 2"/>',
            'lixo' => '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 12.5A1.5 1.5 0 0 0 8.5 21h7a1.5 1.5 0 0 0 1.5-1.5L18 7M9 7V4.5A.5.5 0 0 1 9.5 4h5a.5.5 0 0 1 .5.5V7"/>',
            'seta' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
            'foguete' => '<path d="M5 19l3-1 8-8a4.5 4.5 0 0 0-6.4-6.4l-8 8-1 3z" transform="translate(3 0)"/><path d="M6 14l4 4"/>',
        ];

        return sprintf(
            '<svg class="icon" width="%d" height="%d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">%s</svg>',
            $size,
            $size,
            $paths[$name] ?? '',
        );
    }

    public function logo(int $size = 36): string
    {
        return '<svg class="logo" width="' . $size . '" height="' . $size . '" viewBox="0 0 500 500" aria-hidden="true">'
            . '<rect width="500" height="500" fill="var(--logo-fundo)"/>'
            . '<g transform="translate(50 50) scale(0.8)" fill="none" stroke="var(--logo-traco)" stroke-width="40" stroke-linecap="round" stroke-linejoin="round">'
            . '<path d="M330 170 A115 115 0 1 0 365 250"/>'
            . '<path d="M300 150 L345 165 L330 212"/></g></svg>';
    }

    public function dot(string $status): string
    {
        return '<span class="dot dot--' . $this->e($status) . '" aria-hidden="true"></span>';
    }
}
