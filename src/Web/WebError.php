<?php
declare(strict_types=1);

namespace Axi\Web;

/** Page d'erreur HTML de l'espace web (au lieu du JSON de l'API) */
final class WebError
{
    public static function show(int $status, string $message): never
    {
        http_response_code($status);
        $title = match ($status) {
            403 => 'Accès refusé',
            404 => 'Page introuvable',
            419 => 'Page expirée',
            default => $status >= 500 ? 'Erreur du serveur' : 'Action impossible',
        };
        Html::page($title, '<div class="card">' . View::e($message) . '</div><p><a class="btn ghost" href="' . View::url('/pro') . '">Retour au tableau de bord</a></p>');
    }
}
