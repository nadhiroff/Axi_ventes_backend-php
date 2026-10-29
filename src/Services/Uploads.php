<?php
declare(strict_types=1);

namespace Axi\Services;

use Axi\Core\HttpException;

/**
 * Fichiers envoyés en multipart.
 * Photos : l'original n'est jamais gardé ; deux WebP (thumb ~200 px < 15 Ko, medium ~600 px < 60 Ko)
 * dans un dossier nommé d'après le contenu, donc une URL immuable.
 * Audio : gardé hors de public/ (messages privés aux commerçants).
 */
final class Uploads
{
    private const MAX_PHOTO_BYTES = 8 * 1024 * 1024;
    private const MAX_AUDIO_BYTES = 3 * 1024 * 1024;
    private const SIZES = ['thumb' => [200, 15000], 'medium' => [600, 60000]];

    /** @return array{path: string, width: int, height: int} chemin relatif à public/, taille de « medium » */
    public static function photo(array $file): array
    {
        self::checkUpload($file, self::MAX_PHOTO_BYTES, 'La photo');
        $data = (string) file_get_contents($file['tmp_name']);
        $source = @imagecreatefromstring($data);
        if ($source === false) {
            throw new HttpException(422, 'INVALID_PHOTO', 'La photo n\'a pas pu être lue. Envoyez une image WebP, JPEG ou PNG.');
        }

        $hash = substr(hash('sha256', $data), 0, 20);
        $relative = 'uploads/p/' . substr($hash, 0, 2) . '/' . $hash;
        $dir = BASE_PATH . '/public/' . $relative;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Impossible de créer $dir");
        }

        $result = [];
        foreach (self::SIZES as $name => [$width, $maxBytes]) {
            $image = self::resize($source, $width);
            $result[$name] = [imagesx($image), imagesy($image)];
            // Qualité réduite jusqu'à passer sous le poids visé
            for ($quality = 75; $quality >= 35; $quality -= 10) {
                ob_start();
                imagewebp($image, null, $quality);
                $webp = (string) ob_get_clean();
                if (strlen($webp) <= $maxBytes) {
                    break;
                }
            }
            file_put_contents("$dir/$name.webp", $webp);
        }

        return ['path' => $relative, 'width' => $result['medium'][0], 'height' => $result['medium'][1]];
    }

    /** @return string chemin relatif à storage/ */
    public static function audio(array $file): string
    {
        self::checkUpload($file, self::MAX_AUDIO_BYTES, 'Le message vocal');
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!str_starts_with($mime, 'audio/') && !in_array($mime, ['video/mp4', 'video/3gpp', 'video/webm', 'application/octet-stream'], true)) {
            throw new HttpException(422, 'INVALID_AUDIO', 'Le fichier envoyé n\'est pas un enregistrement audio.');
        }
        $relative = 'audio/' . gmdate('Y/m') . '/' . bin2hex(random_bytes(12)) . '.m4a';
        $path = BASE_PATH . '/storage/' . $relative;
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0775, true) && !is_dir(dirname($path))) {
            throw new \RuntimeException('Impossible de créer ' . dirname($path));
        }
        if (!move_uploaded_file($file['tmp_name'], $path) && !rename($file['tmp_name'], $path)) {
            throw new \RuntimeException("Impossible d'enregistrer $path");
        }
        return $relative;
    }

    private static function checkUpload(array $file, int $maxBytes, string $label): void
    {
        if (($file['error'] ?? UPLOAD_ERR_OK) === UPLOAD_ERR_INI_SIZE || ($file['size'] ?? 0) > $maxBytes) {
            throw new HttpException(413, 'FILE_TOO_LARGE', "$label est trop lourd.");
        }
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            throw new HttpException(422, 'UPLOAD_FAILED', "$label n'a pas été reçu correctement. Réessayez.");
        }
    }

    private static function resize(\GdImage $source, int $maxWidth): \GdImage
    {
        $w = imagesx($source);
        $h = imagesy($source);
        $width = min($maxWidth, $w);
        $height = max(1, (int) round($h * $width / $w));
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagecopyresampled($image, $source, 0, 0, 0, 0, $width, $height, $w, $h);
        return $image;
    }
}
