<?php
declare(strict_types=1);

namespace Axi\Web;

use Axi\Core\Database;
use Axi\Core\Request;

/**
 * Mise à jour en direct de l'espace web sans recharger : la page demande toutes les 8 secondes une empreinte
 * des données (une seule requête légère) ; si elle a changé (un autre agent a validé, un client a payé…),
 * le contenu de la page est remplacé en arrière-plan. Fonctionne chez tout hébergeur (pas de WebSocket).
 * Pas de updated_at sur users ni campaigns : la présence (last_seen_at) et les vues d'annonce le changent sans cesse.
 */
final class Live
{
    public static function version(): string
    {
        $row = Database::one(
            "SELECT CONCAT_WS('|',
                (SELECT CONCAT(COUNT(*), MAX(updated_at)) FROM merchant_applications),
                (SELECT MAX(updated_at) FROM stalls), (SELECT CONCAT(COUNT(*), SUM(status = 'active'), SUM(role = 'merchant')) FROM users), (SELECT MAX(updated_at) FROM products),
                (SELECT MAX(updated_at) FROM orders), (SELECT MAX(updated_at) FROM order_stalls), (SELECT MAX(updated_at) FROM payments),
                (SELECT CONCAT(COUNT(*), SUM(CRC32(CONCAT_WS(':', id, status, audience_type, title, body, url, image_path, video_path, starts_at, ends_at)))) FROM campaigns), (SELECT MAX(updated_at) FROM settings), (SELECT MAX(updated_at) FROM organizations),
                (SELECT CONCAT(COUNT(*), MAX(resolved_at)) FROM disputes), (SELECT CONCAT(COUNT(*), MAX(decided_at)) FROM credits),
                (SELECT CONCAT(COUNT(*), SUM(status = 'done')) FROM agent_visit_requests), (SELECT MAX(id) FROM payouts),
                (SELECT CONCAT(COUNT(*), SUM(active)) FROM aisles), (SELECT CONCAT(COUNT(*), SUM(active)) FROM sites),
                (SELECT CONCAT(COUNT(*), MAX(handled_at)) FROM reports)
             ) AS v",
        );
        return substr(md5((string) $row['v']), 0, 16);
    }

    /** Compteurs affichés en pastille dans le menu « Gestion » */
    public static function counts(): array
    {
        return Database::one(
            "SELECT (SELECT COUNT(*) FROM merchant_applications WHERE status = 'pending') AS applications,
                    (SELECT COUNT(*) FROM disputes WHERE status = 'open') AS disputes,
                    (SELECT COUNT(*) FROM agent_visit_requests WHERE status IN ('new','scheduled')) AS visits,
                    (SELECT COUNT(*) FROM stalls WHERE aisle_other IS NOT NULL OR site_other IS NOT NULL) AS proposals,
                    ((SELECT COUNT(*) FROM reports WHERE status = 'open') + (SELECT COUNT(DISTINCT campaign_id) FROM campaign_reports WHERE status = 'open')) AS reports,
                    (SELECT COUNT(*) FROM credits WHERE status = 'new') AS credits,
                    (SELECT COUNT(*) FROM campaigns WHERE status = 'pending_review') AS campaigns",
        );
    }

    /** GET /pro/pulse : { v } */
    public function pulse(Request $req): never
    {
        if (WebAuth::user() === null) {
            http_response_code(401);
            exit;
        }
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo json_encode(['v' => self::version()]);
        exit;
    }
}
