-- =============================================================================
-- 003 : messagerie comme WhatsApp — reçu (✓✓ gris), lu (✓✓ bleu), « en ligne », message modifié ou supprimé.
-- Base déjà installée : importer CE fichier seul (phpMyAdmin > Importer).
-- =============================================================================

-- Dernière activité de l'application (en ligne = moins d'une minute)
ALTER TABLE users ADD COLUMN last_seen_at DATETIME NULL AFTER merchant_welcome_pending;

-- Messages arrivés sur le téléphone de chaque côté (l'application était ouverte)
ALTER TABLE conversations
  ADD COLUMN client_delivered_at DATETIME NULL AFTER merchant_read_at,
  ADD COLUMN merchant_delivered_at DATETIME NULL AFTER client_delivered_at;

ALTER TABLE messages
  ADD COLUMN edited_at DATETIME NULL AFTER expires_at,
  ADD COLUMN deleted_at DATETIME NULL AFTER edited_at;
