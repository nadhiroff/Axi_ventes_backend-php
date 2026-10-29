<?php
declare(strict_types=1);

namespace Axi\Controllers;

use Axi\Core\HttpException;
use Axi\Core\Request;
use Axi\Services\SignedUrl;

/** Écoute d'un fichier audio privé (storage/audio) par lien signé et temporaire */
final class MediaController
{
    public function audio(Request $req): never
    {
        $path = (string) SignedUrl::verify('/v1/media/audio', $req->queryAll())['p'];
        $file = realpath(BASE_PATH . '/storage/' . $path);
        $root = realpath(BASE_PATH . '/storage/audio');
        if ($file === false || $root === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR) || !is_file($file)) {
            throw new HttpException(404, 'NOT_FOUND', 'Enregistrement introuvable.');
        }
        // Pas de compression gzip : la longueur annoncée doit être celle du fichier
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: audio/mp4');
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: private, max-age=86400');
        readfile($file);
        exit;
    }
}
