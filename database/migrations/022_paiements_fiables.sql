-- =============================================================================
-- 022 : paiements fiables sous charge et en cas de panne.
--   - webhooks enregistrés puis traités par un worker, rejoués jusqu'au succès (jamais perdus) ;
--   - emails et notifications du téléphone envoyés par une file (un SMTP lent ne bloque plus rien) ;
--   - paiement reçu après l'annulation : jamais ignoré (commande réactivée ou litige automatique) ;
--   - transaction payée en trop pour une même référence : enregistrée et remboursée ;
--   - limite de requêtes par adresse IP et par session.
-- Base déjà installée : importer CE fichier seul (phpMyAdmin > Importer), ou php database/migrate.php.
-- =============================================================================

-- provider_ref, reference, claims_paid : ce que dit l'événement signé (lu à la réception, utilisé par le worker)
ALTER TABLE webhook_events
  ADD COLUMN provider_ref VARCHAR(80) NULL AFTER event_key,
  ADD COLUMN reference VARCHAR(40) NULL AFTER provider_ref,
  ADD COLUMN claims_paid TINYINT(1) NULL AFTER reference,
  ADD COLUMN status ENUM('received','processing','done','dead') NOT NULL DEFAULT 'received' AFTER payload,
  ADD COLUMN attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER status,
  ADD COLUMN next_attempt_at DATETIME NULL AFTER attempts,
  ADD COLUMN locked_at DATETIME NULL AFTER next_attempt_at,
  ADD COLUMN last_error VARCHAR(255) NULL AFTER locked_at,
  ADD KEY idx_webhook_queue (status, next_attempt_at);

-- Événements déjà traités avant cette version : terminés ; les autres seront repris par le worker
UPDATE webhook_events SET status = 'done' WHERE processed_at IS NOT NULL;
UPDATE webhook_events SET next_attempt_at = received_at WHERE status = 'received';

-- Emails et notifications du téléphone à envoyer (écrits dans la même transaction que le paiement)
CREATE TABLE outbox_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kind ENUM('email','push') NOT NULL,
  payload MEDIUMTEXT NOT NULL,
  status ENUM('pending','processing','done','dead') NOT NULL DEFAULT 'pending',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  next_attempt_at DATETIME NOT NULL,
  locked_at DATETIME NULL,
  last_error VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  KEY idx_outbox_queue (status, next_attempt_at)
) ENGINE=InnoDB;

-- Dernier signe de vie du worker : sans worker actif, les requêtes traitent elles-mêmes leurs tâches
CREATE TABLE worker_heartbeats (
  name VARCHAR(40) PRIMARY KEY,
  beat_at DATETIME NOT NULL
) ENGINE=InnoDB;

-- Compteurs de requêtes par fenêtre d'une minute (clé : IP ou session)
CREATE TABLE rate_limits (
  bucket CHAR(40) NOT NULL,
  window_start INT UNSIGNED NOT NULL,
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (bucket, window_start),
  KEY idx_rate_window (window_start)
) ENGINE=InnoDB;

-- claimed_provider_ref : transaction annoncée par la page de paiement, pas encore vérifiée
--   (empêche de rouvrir le paiement pendant la vérification : jamais deux débits) ;
-- late_paid_at : paiement confirmé par la passerelle après l'annulation ou l'expiration de la commande
ALTER TABLE payments
  ADD COLUMN claimed_provider_ref VARCHAR(80) NULL AFTER provider_ref,
  ADD COLUMN late_paid_at DATETIME NULL AFTER paid_at,
  ADD KEY idx_payments_recheck (status, checked_at);

-- Transaction réussie EN PLUS de celle déjà encaissée pour la même référence (paiement fait deux fois)
CREATE TABLE payment_duplicates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  payment_id INT UNSIGNED NOT NULL,
  provider VARCHAR(20) NOT NULL,
  provider_ref VARCHAR(80) NOT NULL,
  amount INT UNSIGNED NULL,
  refund_status ENUM('succeeded','pending','failed','simulated') NOT NULL,
  refund_message VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_payment_duplicate (provider, provider_ref),
  CONSTRAINT fk_duplicates_payment FOREIGN KEY (payment_id) REFERENCES payments(id)
) ENGINE=InnoDB;

-- Versement en cours : les parts sont réservées AVANT l'appel à la passerelle (jamais versées deux fois)
ALTER TABLE payouts
  MODIFY COLUMN status ENUM('processing','pending','simulated','paid','failed') NOT NULL DEFAULT 'pending';
