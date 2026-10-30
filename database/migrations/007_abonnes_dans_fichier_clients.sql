-- =============================================================================
-- 007 : les personnes qui suivent un étal apparaissent dans « Mes clients » du commerçant.
-- Base déjà installée : importer CE fichier seul (phpMyAdmin > Importer).
-- =============================================================================
INSERT IGNORE INTO merchant_clients (stall_id, user_id, display_name, email, created_at)
SELECT f.stall_id, u.id, CONCAT(u.first_name, ' ', LEFT(u.last_name, 1), '.'), u.email, f.created_at
FROM stall_follows f JOIN users u ON u.id = f.user_id
WHERE u.status = 'active';
