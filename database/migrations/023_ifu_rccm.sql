-- =============================================================================
-- 023 : documents obligatoires pour vendre au Bénin, joints à la demande « Devenir commerçant » :
--   IFU (Identifiant Fiscal Unique) et RCCM (Registre du Commerce et du Crédit Mobilier), en PDF.
--   Gardés hors de public/, lus uniquement par un agent Axi connecté.
--   NULL pour les demandes envoyées avant cette version.
-- Base déjà installée : importer CE fichier seul (phpMyAdmin > Importer), ou php database/migrate.php.
-- =============================================================================
ALTER TABLE merchant_applications
  ADD COLUMN ifu_path VARCHAR(255) NULL AFTER selfie_path,
  ADD COLUMN rccm_path VARCHAR(255) NULL AFTER ifu_path;
