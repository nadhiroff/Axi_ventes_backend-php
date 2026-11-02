<?php
declare(strict_types=1);

namespace Axi\Services;

use Axi\Core\Auth;
use Axi\Core\Clock;
use Axi\Core\Database;
use Axi\Core\HttpException;
use Axi\Core\Logger;

/**
 * Effacement définitif d'un compte (demande de suppression acceptée par un agent Axi) : informations personnelles,
 * pièces d'identité, IFU, RCCM, photos, messages, discussions, étal, historique de navigation… sont supprimés,
 * fichiers compris. Seules restent les lignes que la loi et les autres personnes exigent (commandes, paiements,
 * litiges, crédits, versements) : elles sont rattachées à un compte vide « Compte supprimé », sans nom, email,
 * téléphone ni adresse. Si rien de tel n'existe, la ligne du compte elle-même est supprimée.
 */
final class AccountEraser
{
    /** Colonnes de chemins de PHOTOS publiques (dossiers partagés par contenu : effacés seulement si plus personne ne s'en sert) */
    private const PUBLIC_PHOTOS = [
        ['users', 'avatar_path'], ['stalls', 'photo_path'], ['stalls', 'verification_photo_path'], ['stall_photos', 'path'],
        ['product_images', 'path'], ['messages', 'file_path'], ['stall_announcement_photos', 'path'], ['stall_announcements', 'photo_path'],
        ['merchant_applications', 'stall_photo_path'], ['campaigns', 'image_path'],
    ];

    /** Argent encore en jeu : on n'efface pas (commande payée pas encore reçue, litige, argent bloqué pour le commerçant) */
    public static function blockingReason(int $userId): ?string
    {
        $open = (int) Database::value("SELECT COUNT(*) FROM orders WHERE client_id = ? AND status IN ('paid','preparing','on_the_way','disputed')", [$userId]);
        $merchantOpen = (int) Database::value("SELECT COUNT(*) FROM order_stalls os JOIN stalls s ON s.id = os.stall_id WHERE s.user_id = ? AND os.escrow_state IN ('held','disputed')", [$userId]);
        $toPay = (int) Database::value("SELECT COALESCE(SUM(os.net_amount), 0) FROM order_stalls os JOIN stalls s ON s.id = os.stall_id WHERE s.user_id = ? AND os.escrow_state = 'released' AND os.payout_id IS NULL", [$userId]);
        $credit = (int) Database::value("SELECT COUNT(*) FROM credits WHERE merchant_user_id = ? AND status IN ('accepted','granted')", [$userId]);
        return match (true) {
            $open > 0 => "$open commande(s) payée(s) pas encore reçue(s) ou en litige.",
            $merchantOpen > 0 => "$merchantOpen vente(s) avec de l'argent bloqué ou en litige.",
            $toPay > 0 => 'Argent libéré pas encore versé au commerçant (' . number_format($toPay, 0, ',', ' ') . ' FCFA).',
            $credit > 0 => 'Crédit en cours de remboursement.',
            default => null,
        };
    }

    /** @return array{removed_row: bool, files: int} */
    public static function erase(int $userId): array
    {
        $user = Database::one('SELECT * FROM users WHERE id = ?', [$userId]) ?? throw new HttpException(404, 'NOT_FOUND', 'Compte introuvable.');
        if (($reason = self::blockingReason($userId)) !== null) {
            throw new HttpException(409, 'MONEY_IN_PROGRESS', 'Effacement impossible pour l\'instant : ' . $reason . ' Réglez-le d\'abord.');
        }
        $stallId = Database::value('SELECT id FROM stalls WHERE user_id = ?', [$userId]);
        $stallId = $stallId === null ? null : (int) $stallId;
        $privateFiles = [];
        $publicPhotos = [];

        // --- Fichiers à effacer, relevés AVANT de supprimer les lignes qui les désignent ------------------------
        $publicPhotos[] = $user['avatar_path'];
        foreach (Database::all('SELECT * FROM merchant_applications WHERE user_id = ?', [$userId]) as $a) {
            array_push($privateFiles, $a['id_front_path'], $a['id_back_path'], $a['selfie_path'], $a['ifu_path'], $a['rccm_path']);
            $publicPhotos[] = $a['stall_photo_path'];
        }
        $convIds = array_map('intval', array_column(Database::all('SELECT id FROM conversations WHERE client_id = ? OR stall_id = ?', [$userId, $stallId ?? 0]), 'id'));
        if ($convIds !== []) {
            foreach (Database::all('SELECT kind, file_path FROM messages WHERE conversation_id IN (' . Database::in($convIds) . ') AND file_path IS NOT NULL', $convIds) as $m) {
                $m['kind'] === 'photo' ? $publicPhotos[] = $m['file_path'] : $privateFiles[] = $m['file_path'];
            }
        }
        foreach (Database::all('SELECT audio_path FROM voice_messages WHERE user_id = ? OR stall_id = ?', [$userId, $stallId ?? 0]) as $v) {
            $privateFiles[] = $v['audio_path'];
        }
        if ($stallId !== null) {
            $stall = Database::one('SELECT * FROM stalls WHERE id = ?', [$stallId]);
            array_push($publicPhotos, $stall['photo_path'], $stall['verification_photo_path']);
            $publicPhotos = [...$publicPhotos, ...array_column(Database::all('SELECT path FROM stall_photos WHERE stall_id = ?', [$stallId]), 'path')];
            $publicPhotos = [...$publicPhotos, ...array_column(Database::all('SELECT ap.path FROM stall_announcement_photos ap JOIN stall_announcements a ON a.id = ap.announcement_id WHERE a.stall_id = ?', [$stallId]), 'path')];
            $publicPhotos = [...$publicPhotos, ...array_column(Database::all('SELECT photo_path FROM stall_announcements WHERE stall_id = ?', [$stallId]), 'photo_path')];
            $publicPhotos = [...$publicPhotos, ...array_column(Database::all('SELECT pi.path FROM product_images pi JOIN products p ON p.id = pi.product_id WHERE p.stall_id = ?', [$stallId]), 'path')];
            $privateFiles = [...$privateFiles, ...array_column(Database::all('SELECT audio_path FROM products WHERE stall_id = ? AND audio_path IS NOT NULL', [$stallId]), 'audio_path')];
            $privateFiles = [...$privateFiles, ...array_column(Database::all('SELECT audio_path FROM broadcasts WHERE stall_id = ? AND audio_path IS NOT NULL', [$stallId]), 'audio_path')];
        }

        // Ce qui doit rester pour la comptabilité et pour l'autre partie : commandes, paiements, litiges, crédits, versements
        $keepRow = self::referenced($userId, $stallId);

        $removedRow = Database::transaction(static function () use ($userId, $user, $stallId, $convIds, $keepRow): bool {
            if ($convIds !== []) {
                Database::run('DELETE FROM reports WHERE conversation_id IN (' . Database::in($convIds) . ')', $convIds);
                Database::run('DELETE FROM conversations WHERE id IN (' . Database::in($convIds) . ')', $convIds); // messages : en cascade
            }
            Database::run('DELETE FROM email_codes WHERE email = ?', [$user['email']]);
            Database::run('DELETE FROM login_events WHERE user_id = ? OR email = ?', [$userId, $user['email']]);
            Database::run('DELETE FROM merchant_clients WHERE user_id = ? OR (? IS NOT NULL AND email = ?) OR (? IS NOT NULL AND phone = ?)', [$userId, $user['email'], $user['email'], $user['phone'], $user['phone']]);
            foreach (['user_settings', 'favorites', 'product_views', 'stall_follows', 'notifications', 'push_tokens', 'sales_hidden', 'auth_tokens',
                'profile_changes', 'message_hidden', 'stall_emails', 'campaign_reports', 'agent_visit_requests', 'merchant_applications', 'voice_messages'] as $table) {
                Database::run("DELETE FROM $table WHERE user_id = ?", [$userId]);
            }
            Database::run('DELETE FROM user_blocks WHERE blocker_id = ? OR blocked_id = ?', [$userId, $userId]);
            Database::run('DELETE FROM reports WHERE reporter_id = ? OR target_user_id = ?', [$userId, $userId]);
            Database::run('DELETE FROM data_sharing_consents WHERE merchant_user_id = ?', [$userId]);
            // Commandes et paiements gardés pour la comptabilité, sans adresse ni numéro
            Database::run("UPDATE orders SET reception_detail = 'Effacé', payment_phone = NULL WHERE client_id = ?", [$userId]);
            Database::run('UPDATE payments SET phone = NULL WHERE user_id = ?', [$userId]);

            if ($stallId !== null) {
                Database::run('DELETE FROM stall_follows WHERE stall_id = ?', [$stallId]);
                Database::run('DELETE FROM merchant_clients WHERE stall_id = ?', [$stallId]);
                Database::run('DELETE FROM stall_photos WHERE stall_id = ?', [$stallId]);
                Database::run('DELETE FROM stall_announcements WHERE stall_id = ?', [$stallId]); // photos : en cascade
                Database::run('DELETE FROM broadcasts WHERE stall_id = ?', [$stallId]);
                Database::run('DELETE FROM voice_messages WHERE stall_id = ?', [$stallId]);
                Database::run('UPDATE payouts SET phone = NULL WHERE stall_id = ?', [$stallId]);
                $sold = Database::value('SELECT 1 FROM order_items i JOIN products p ON p.id = i.product_id WHERE p.stall_id = ? LIMIT 1', [$stallId]) !== null;
                if ($sold || $keepRow) {
                    // Ventes passées : l'étal reste comme coquille vide, ses produits anonymes (les commandes gardent leur copie du nom)
                    Database::run("DELETE pi FROM product_images pi JOIN products p ON p.id = pi.product_id WHERE p.stall_id = ?", [$stallId]);
                    Database::run("UPDATE products SET status = 'deleted', name = 'Produit supprimé', audio_path = NULL WHERE stall_id = ?", [$stallId]);
                    Database::run(
                        "UPDATE stalls SET name = 'Étal supprimé', tagline = NULL, phone = NULL, whatsapp = NULL, photo_path = NULL, verification_photo_path = NULL,
                         row_label = NULL, stand = NULL, site_other = NULL, aisle_other = NULL, delivery_note = NULL, map_url = NULL, status = 'closed' WHERE id = ?",
                        [$stallId],
                    );
                } else {
                    Database::run('DELETE FROM products WHERE stall_id = ?', [$stallId]); // images et vues : en cascade
                    Database::run('DELETE FROM subscriptions WHERE stall_id = ?', [$stallId]);
                    Database::run('DELETE FROM stalls WHERE id = ?', [$stallId]);
                }
            }
            if (!$keepRow && ($stallId === null || Database::value('SELECT 1 FROM stalls WHERE id = ?', [$stallId]) === null)) {
                Database::run('DELETE FROM users WHERE id = ?', [$userId]);
                return true;
            }
            Database::run(
                "UPDATE users SET first_name = 'Compte', last_name = 'supprimé', name = 'Compte supprimé', email = ?, phone = NULL, bio = NULL, avatar_path = NULL,
                 password_hash = ?, status = 'deleted', suspended_reason = NULL, last_seen_at = NULL, deleted_at = ? WHERE id = ?",
                ['supprime-' . $userId . '@deleted.invalid', password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), Clock::now(), $userId],
            );
            return false;
        });
        Auth::revokeAll($userId);

        // Fichiers : privés toujours (noms aléatoires, à lui seul) ; photos publiques si plus aucune ligne ne s'en sert
        $deleted = 0;
        foreach (array_unique(array_filter($privateFiles)) as $path) {
            $deleted += (int) self::unlinkInside(BASE_PATH . '/storage/' . $path, BASE_PATH . '/storage');
        }
        foreach (array_unique(array_filter($publicPhotos)) as $path) {
            if (!self::photoInUse($path)) {
                $deleted += self::removePhotoDir($path);
            }
        }
        Logger::write('security', "COMPTE EFFACÉ #$userId (" . ($removedRow ? 'ligne supprimée' : 'coquille anonyme gardée pour la comptabilité') . ", $deleted fichier(s))");
        return ['removed_row' => $removedRow, 'files' => $deleted];
    }

    /** Lignes qui doivent survivre (comptabilité, autre partie) et qui pointent vers ce compte ou son étal */
    private static function referenced(int $userId, ?int $stallId): bool
    {
        $checks = [
            ['SELECT 1 FROM orders WHERE client_id = ? LIMIT 1', [$userId]],
            ['SELECT 1 FROM payments WHERE user_id = ? LIMIT 1', [$userId]],
            ['SELECT 1 FROM disputes WHERE client_id = ? LIMIT 1', [$userId]],
            ['SELECT 1 FROM credits WHERE merchant_user_id = ? LIMIT 1', [$userId]],
            ['SELECT 1 FROM admin_actions WHERE actor_id = ? LIMIT 1', [$userId]],
            ['SELECT 1 FROM campaigns WHERE created_by = ? LIMIT 1', [$userId]],
        ];
        if ($stallId !== null) {
            $checks[] = ['SELECT 1 FROM order_stalls WHERE stall_id = ? LIMIT 1', [$stallId]];
            $checks[] = ['SELECT 1 FROM payouts WHERE stall_id = ? LIMIT 1', [$stallId]];
            $checks[] = ['SELECT 1 FROM subscriptions WHERE stall_id = ? AND status = \'active\' LIMIT 1', [$stallId]];
        }
        foreach ($checks as [$sql, $params]) {
            if (Database::value($sql, $params) !== null) {
                return true;
            }
        }
        return false;
    }

    private static function photoInUse(string $path): bool
    {
        foreach (self::PUBLIC_PHOTOS as [$table, $column]) {
            if (Database::value("SELECT 1 FROM $table WHERE $column = ? LIMIT 1", [$path]) !== null) {
                return true;
            }
        }
        return false;
    }

    /** Dossier d'une photo publique (uploads/p/xx/hash : thumb.webp, medium.webp…) */
    private static function removePhotoDir(string $relative): int
    {
        $dir = realpath(BASE_PATH . '/public/' . $relative);
        $root = realpath(BASE_PATH . '/public/uploads');
        if ($dir === false || $root === false || !str_starts_with($dir, $root . DIRECTORY_SEPARATOR) || !is_dir($dir)) {
            return 0;
        }
        $n = 0;
        foreach (glob($dir . '/*') ?: [] as $file) {
            $n += (int) (is_file($file) && @unlink($file));
        }
        @rmdir($dir);
        return $n;
    }

    private static function unlinkInside(string $file, string $root): bool
    {
        $real = realpath($file);
        $base = realpath($root);
        return $real !== false && $base !== false && str_starts_with($real, $base . DIRECTORY_SEPARATOR) && is_file($real) && @unlink($real);
    }
}
