<?php
declare(strict_types=1);

namespace Axi\Services;

use Axi\Core\HttpException;

/**
 * Enregistre photos et vocaux dans public/uploads/{images|audio}/AAAA/MM/.
 * Le type est vérifié sur le contenu réel du fichier, jamais sur son extension.
 * Conseil côté application : compresser les photos (1080 px, qualité 0,6) avant l'envoi.
 */
final class UploadService
{
    private const KINDS = [
        'image' => [
            'dir' => 'images',
            'max_bytes' => 5 * 1024 * 1024,
            'types' => ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'],
        ],
        'audio' => [
            'dir' => 'audio',
            'max_bytes' => 10 * 1024 * 1024,
            'types' => [
                'audio/mpeg' => 'mp3', 'audio/mp3' => 'mp3',
                'audio/webm' => 'webm', 'video/webm' => 'webm',
                'audio/ogg' => 'ogg', 'application/ogg' => 'ogg',
                'audio/mp4' => 'm4a', 'audio/x-m4a' => 'm4a', 'video/mp4' => 'm4a',
                'audio/aac' => 'aac', 'audio/x-hx-aac-adts' => 'aac',
                'audio/wav' => 'wav', 'audio/x-wav' => 'wav',
            ],
        ],
    ];

    /** Renvoie le chemin relatif (uploads/images/2026/10/abc.jpg) ou null si aucun fichier */
    public static function store(?array $file, string $kind, string $field): ?string
    {
        if ($file === null) {
            return null;
        }
        $spec = self::KINDS[$kind];
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE || (int) $file['size'] > $spec['max_bytes']) {
            throw new HttpException(413, 'FILE_TOO_LARGE', 'Fichier trop volumineux', ['field' => $field, 'max_bytes' => $spec['max_bytes']]);
        }
        if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            throw new HttpException(400, 'UPLOAD_FAILED', 'Échec de l\'envoi du fichier', ['field' => $field]);
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $ext = $spec['types'][$mime] ?? null;
        if ($ext === null) {
            throw new HttpException(415, 'FILE_TYPE_NOT_ALLOWED', 'Format de fichier non accepté', ['field' => $field, 'detected' => $mime]);
        }

        $relativeDir = 'uploads/' . $spec['dir'] . '/' . gmdate('Y/m');
        $absoluteDir = BASE_PATH . '/public/' . $relativeDir;
        if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0755, true) && !is_dir($absoluteDir)) {
            throw new \RuntimeException("Impossible de créer $absoluteDir");
        }
        $name = bin2hex(random_bytes(16)) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], "$absoluteDir/$name")) {
            throw new \RuntimeException('Impossible d\'enregistrer le fichier envoyé');
        }
        return "$relativeDir/$name";
    }

    /** Supprime un ancien fichier remplacé */
    public static function delete(?string $path): void
    {
        if ($path !== null && str_starts_with($path, 'uploads/') && !str_contains($path, '..')) {
            @unlink(BASE_PATH . '/public/' . $path);
        }
    }
}
