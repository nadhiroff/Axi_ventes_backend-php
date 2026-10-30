-- =============================================================================
-- 021 : adresse de contact montrée aux comptes bloqués, pour connaître la raison ou contester.
--       Vide : l'adresse d'envoi des emails d'Axi est utilisée.
-- Base déjà installée : importer CE fichier seul (phpMyAdmin > Importer).
-- =============================================================================
INSERT INTO settings (k, v, label, type) VALUES ('support_email', '', 'Email de contact des comptes bloqués (vide = adresse d''envoi des emails)', 'text');
