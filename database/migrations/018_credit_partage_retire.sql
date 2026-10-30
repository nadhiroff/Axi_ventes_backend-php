-- =============================================================================
-- 018 : une demande de crédit ne peut pas rester « en cours » sans accord de partage :
--       personne ne peut l'étudier. Les demandes déjà dans ce cas sont closes.
-- Base déjà installée : importer CE fichier seul (phpMyAdmin > Importer).
-- =============================================================================
UPDATE credits c SET c.status = 'declined', c.note = 'Demande close : partage de l''historique retiré.', c.decided_at = UTC_TIMESTAMP()
WHERE c.status IN ('new', 'in_review')
  AND NOT EXISTS (SELECT 1 FROM data_sharing_consents k WHERE k.merchant_user_id = c.merchant_user_id AND k.organization_id = c.organization_id AND k.revoked_at IS NULL);
