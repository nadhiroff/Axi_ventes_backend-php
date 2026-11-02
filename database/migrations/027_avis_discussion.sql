-- =============================================================================
-- 027 : avis dans la discussion (comme WhatsApp) : « … a réglé les messages éphémères sur 48 h ».
--   kind = 'system' ; body = le nombre d'heures ; jamais compté comme non lu, jamais notifié, jamais effacé
--   avec les messages éphémères.
-- Base déjà installée : importer CE fichier seul (phpMyAdmin > Importer), ou php database/migrate.php.
-- =============================================================================
ALTER TABLE messages MODIFY kind ENUM('text','photo','voice','video','system') NOT NULL DEFAULT 'text';
