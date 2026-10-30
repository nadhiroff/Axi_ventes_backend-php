-- =============================================================================
-- 016 : demande commerçant et étal : jusqu'à 2 autres numéros (MTN, Moov, Celtiis : un par opérateur).
--       Pièce d'identité : « Autre » n'est plus proposé (CNI, CIP ou passeport).
-- Base déjà installée : importer CE fichier seul (phpMyAdmin > Importer).
-- =============================================================================
ALTER TABLE merchant_applications ADD COLUMN other_phones VARCHAR(80) NULL AFTER phone;
ALTER TABLE stalls ADD COLUMN other_phones VARCHAR(80) NULL AFTER whatsapp;
