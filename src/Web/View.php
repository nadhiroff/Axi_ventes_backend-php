<?php
declare(strict_types=1);

namespace Axi\Web;

use Axi\Core\Url;

/** Rendu des pages de l'espace web : gabarit commun (menu selon le sous-type), échappement systématique. */
final class View
{
    public static function render(string $template, array $vars = [], ?string $title = null): never
    {
        $user = WebAuth::user();
        $flash = WebAuth::flash();
        extract($vars, EXTR_SKIP);
        ob_start();
        require __DIR__ . '/views/' . $template . '.php';
        $content = (string) ob_get_clean();
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'");
        require __DIR__ . '/views/layout.php';
        exit;
    }

    public static function redirect(string $path, ?string $flash = null, string $type = 'ok'): never
    {
        if ($flash !== null) {
            WebAuth::flash($flash, $type);
        }
        header('Location: ' . Url::to($path));
        exit;
    }

    /** Échappement HTML */
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    public static function url(string $path, array $query = []): string
    {
        $query = array_filter($query, static fn ($v) => $v !== null && $v !== '');
        return self::e(Url::to($path) . ($query === [] ? '' : '?' . http_build_query($query)));
    }

    public static function fcfa(mixed $amount): string
    {
        return number_format((int) $amount, 0, ',', ' ') . ' FCFA';
    }

    /** Date UTC de la base, affichée à l'heure du Bénin */
    public static function date(?string $utc, bool $time = true): string
    {
        if ($utc === null || $utc === '') {
            return '—';
        }
        $d = (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Africa/Porto-Novo'));
        return $d->format($time ? 'd/m/Y H:i' : 'd/m/Y');
    }

    /** Champ caché CSRF à placer dans chaque formulaire POST */
    public static function csrf(): string
    {
        return '<input type="hidden" name="_csrf" value="' . self::e(WebAuth::csrf()) . '">';
    }

    /** Formulaire POST d'une action, avec confirmation « Êtes-vous sûr… ? » si $confirm est donné */
    public static function action(string $path, string $label, array $fields = [], ?string $confirm = null, string $class = 'btn'): string
    {
        $html = '<form method="post" action="' . self::url($path) . '" class="inline"' . ($confirm !== null ? ' onsubmit="return confirm(' . self::e(json_encode($confirm, JSON_UNESCAPED_UNICODE)) . ')"' : '') . '>' . self::csrf();
        foreach ($fields as $name => $value) {
            $html .= '<input type="hidden" name="' . self::e($name) . '" value="' . self::e($value) . '">';
        }
        return $html . '<button type="submit" class="' . self::e($class) . '">' . self::e($label) . '</button></form>';
    }

    public static function badge(string $label, string $tone = 'muted'): string
    {
        return '<span class="badge ' . self::e($tone) . '">' . self::e($label) . '</span>';
    }

    /** Pagination simple ?page= */
    public static function page(): int
    {
        return max(1, (int) ($_GET['page'] ?? 1));
    }

    public static function pager(string $path, array $query, int $page, bool $hasMore): string
    {
        $html = '<nav class="pager">';
        if ($page > 1) {
            $html .= '<a class="btn ghost" href="' . self::url($path, ['page' => $page - 1] + $query) . '">← Précédent</a>';
        }
        if ($hasMore) {
            $html .= '<a class="btn ghost" href="' . self::url($path, ['page' => $page + 1] + $query) . '">Suivant →</a>';
        }
        return $html . '</nav>';
    }
}
