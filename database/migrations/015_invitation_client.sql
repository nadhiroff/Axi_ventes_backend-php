-- =============================================================================
-- 015 : un client (ou commerçant) ajouté à la main par un commerçant doit ACCEPTER avant toute discussion.
--       Suivre l'étal ou y commander vaut accord. Les clients déjà dans les fichiers restent acceptés.
-- Base déjà installée : importer CE fichier seul (phpMyAdmin > Importer).
-- =============================================================================
ALTER TABLE merchant_clients
  ADD COLUMN invite_status ENUM('accepted','pending','declined') NOT NULL DEFAULT 'accepted' AFTER user_id,
  ADD COLUMN invited_at DATETIME NULL AFTER invite_status,
  ADD COLUMN answered_at DATETIME NULL AFTER invited_at;
