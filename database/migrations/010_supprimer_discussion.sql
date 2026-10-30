-- =============================================================================
-- 010 : supprimer une discussion « pour moi » (l'autre personne la garde ; elle revient si un nouveau message arrive).
-- Base déjà installée : importer CE fichier seul (phpMyAdmin > Importer).
-- =============================================================================
ALTER TABLE conversations
  ADD COLUMN client_cleared_msg_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER merchant_delivered_msg_id,
  ADD COLUMN merchant_cleared_msg_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER client_cleared_msg_id;
