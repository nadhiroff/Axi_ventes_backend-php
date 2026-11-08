-- =============================================================================
-- 028 : annonces groupées dans les discussions, résiliation d'abonnement, canal des envois groupés.
--   messages.is_announcement : message envoyé à plusieurs clients à la fois (badge « Annonce » dans la discussion).
--   subscriptions.cancelled_at : résilié par le commerçant ; l'abonnement reste actif jusqu'à ends_on,
--     sans rappel de renouvellement ni remboursement.
--   broadcasts.channel : envoi dans Axi ou préparé pour WhatsApp.
-- Base déjà installée : importer CE fichier seul (phpMyAdmin > Importer), ou php database/migrate.php.
-- =============================================================================
ALTER TABLE messages ADD COLUMN is_announcement TINYINT(1) NOT NULL DEFAULT 0 AFTER kind;
ALTER TABLE subscriptions ADD COLUMN cancelled_at DATETIME DEFAULT NULL AFTER ends_on;
ALTER TABLE broadcasts ADD COLUMN channel ENUM('axi','whatsapp') NOT NULL DEFAULT 'axi' AFTER segment;
