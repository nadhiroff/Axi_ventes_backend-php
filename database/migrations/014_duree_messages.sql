-- =============================================================================
-- 014 : durée des messages réglée par le commerçant, discussion par discussion.
--       24 h par défaut pour tout le monde ; 48 h au plus sans abonnement ; 30 ou 90 jours abonné.
--       Suivre l'étal ne garde plus les messages : les messages gardés jusqu'ici partent dans 30 jours.
-- Base déjà installée : importer CE fichier seul (phpMyAdmin > Importer).
-- =============================================================================
ALTER TABLE conversations ADD COLUMN retention_hours SMALLINT UNSIGNED NOT NULL DEFAULT 24 AFTER stall_id;

UPDATE messages SET expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 DAY) WHERE expires_at IS NULL;
