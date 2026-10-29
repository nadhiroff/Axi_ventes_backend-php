-- =============================================================================
-- 005 : coches ✓✓ fiables — dernier message reçu et lu de chaque côté (par numéro, pas par heure).
-- Base déjà installée : importer CE fichier seul (phpMyAdmin > Importer).
-- =============================================================================
ALTER TABLE conversations
  ADD COLUMN client_read_msg_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER merchant_delivered_at,
  ADD COLUMN merchant_read_msg_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER client_read_msg_id,
  ADD COLUMN client_delivered_msg_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER merchant_read_msg_id,
  ADD COLUMN merchant_delivered_msg_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER client_delivered_msg_id;
