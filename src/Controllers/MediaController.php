<?php
declare(strict_types=1);

namespace Axi\Controllers;

use Axi\Core\HttpException;
use Axi\Core\Request;
use Axi\Services\SignedUrl;

/** Écoute d'un vocal ou lecture d'une vidéo privés (storage/audio, storage/video) par lien signé et temporaire */
final class MediaController
{
    public function audio(Request $req): never
    {
        $this->stream((string) SignedUrl::verify('/v1/media/audio', $req->queryAll())['p'], 'audio', 'audio/mp4');
    }

    public function video(Request $req): never
    {
        $this->stream((string) SignedUrl::verify('/v1/media/video', $req->queryAll())['p'], 'video', 'video/mp4');
    }

    private function stream(string $path, string $folder, string $type): never
    {
        $file = realpath(BASE_PATH . '/storage/' . $path);
        $root = realpath(BASE_PATH . '/storage/' . $folder);
        if ($file === false || $root === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR) || !is_file($file)) {
            throw new HttpException(404, 'NOT_FOUND', 'Fichier introuvable.');
        }
        // Pas de compression gzip : la longueur annoncée doit être celle du fichier
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        // Le lecteur de l'iPhone (AVPlayer, audio et vidéo) lit par morceaux : sans réponse 206 aux requêtes Range, il refuse de lire
        $size = (int) filesize($file);
        $start = 0;
        $end = $size - 1;
        header('Content-Type: ' . $type);
        header('Accept-Ranges: bytes');
        header('Cache-Control: private, max-age=86400');
        if (preg_match('/^bytes=(\d*)-(\d*)$/', (string) ($_SERVER['HTTP_RANGE'] ?? ''), $m)) {
            if ($m[1] === '' && $m[2] !== '') {
                $start = max(0, $size - (int) $m[2]);
            } else {
                $start = (int) $m[1];
                $end = $m[2] === '' ? $end : min($end, (int) $m[2]);
            }
            if ($start > $end || $start >= $size) {
                http_response_code(416);
                header("Content-Range: bytes */$size");
                exit;
            }
            http_response_code(206);
            header("Content-Range: bytes $start-$end/$size");
        }
        header('Content-Length: ' . ($end - $start + 1));
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
            exit;
        }
        $fp = fopen($file, 'rb');
        fseek($fp, $start);
        $left = $end - $start + 1;
        while ($left > 0 && !feof($fp)) {
            $chunk = fread($fp, (int) min(65536, $left));
            echo $chunk;
            $left -= strlen($chunk);
        }
        fclose($fp);
        exit;
    }
}
