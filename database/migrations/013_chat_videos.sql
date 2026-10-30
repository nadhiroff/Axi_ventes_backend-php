-- =============================================================================
-- 013 : vidéos dans les discussions (une minute au plus, fichier dans storage/video).
-- Base déjà installée : importer CE fichier seul (phpMyAdmin > Importer).
-- =============================================================================
ALTER TABLE messages MODIFY kind ENUM('text','photo','voice','video') NOT NULL DEFAULT 'text';
