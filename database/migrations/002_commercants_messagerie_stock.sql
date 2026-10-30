-- =============================================================================
-- 002 : devenir commerçant sur demande, photo de profil, messagerie client ↔ commerçant,
--       stock des produits, abonnement mensuel ou annuel, paiement sur place.
-- Base déjà installée avec 001 : importer CE fichier seul (phpMyAdmin > Importer).
-- =============================================================================

-- Photo de profil (facultative) et qui peut la voir ; message de félicitations affiché une seule fois
ALTER TABLE users
  ADD COLUMN avatar_path VARCHAR(255) NULL AFTER phone,
  ADD COLUMN avatar_visibility ENUM('everyone','contacts','nobody') NOT NULL DEFAULT 'everyone' AFTER avatar_path,
  ADD COLUMN merchant_welcome_pending TINYINT(1) NOT NULL DEFAULT 0 AFTER avatar_visibility;

-- Tout le monde s'inscrit comme client ; pour vendre, on envoie une demande vérifiée par un agent Axi.
-- Les pièces d'identité sont gardées hors de public/ (storage/private) et visibles des seuls agents Axi.
CREATE TABLE merchant_applications (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  phone VARCHAR(20) NOT NULL,
  id_type ENUM('cni','cip','passport','other') NOT NULL,
  id_number VARCHAR(40) NOT NULL,
  id_front_path VARCHAR(255) NOT NULL,
  id_back_path VARCHAR(255) NULL,
  selfie_path VARCHAR(255) NOT NULL,
  stall_photo_path VARCHAR(255) NOT NULL,     -- photo de l'étal à son emplacement réel
  stall_name VARCHAR(100) NOT NULL,
  merchant_type ENUM('retail','wholesale') NOT NULL DEFAULT 'retail',
  site_id VARCHAR(30) NULL,
  site_other VARCHAR(100) NULL,               -- « Autre » : marché proposé par le commerçant
  aisle_id VARCHAR(30) NULL,
  aisle_other VARCHAR(100) NULL,              -- « Autre » : allée proposée par le commerçant
  row_label VARCHAR(30) NULL,
  stand VARCHAR(20) NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  reject_reason VARCHAR(300) NULL,
  reviewed_by INT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_applications_user (user_id, id),
  KEY idx_applications_status (status, id),
  CONSTRAINT fk_applications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_applications_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Allée ou marché « Autre » : l'étal est rangé dans « Autres » en attendant qu'un agent crée la vraie allée
ALTER TABLE stalls
  ADD COLUMN site_other VARCHAR(100) NULL AFTER site_id,
  ADD COLUMN aisle_other VARCHAR(100) NULL AFTER aisle_id;

INSERT INTO sites (id, name, sort_order) VALUES ('autre', 'Autre marché', 99);
INSERT INTO aisles (id, name, icon, sort_order) VALUES ('autres', 'Autres', 'other', 99);

-- Stock : NULL = non suivi ; 0 = épuisé (le produit disparaît pour les clients jusqu'au réassort).
-- « paused » : produit masqué automatiquement au-delà de la limite sans abonnement.
ALTER TABLE products
  ADD COLUMN stock_quantity INT UNSIGNED NULL AFTER in_stock,
  MODIFY COLUMN status ENUM('draft','active','paused','deleted') NOT NULL DEFAULT 'active';

-- Messagerie : une conversation par (étal, client). Les messages disparaissent après 24 h,
-- sauf si le client suit l'étal (expires_at NULL).
CREATE TABLE conversations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stall_id INT UNSIGNED NOT NULL,
  client_id INT UNSIGNED NOT NULL,
  last_message_at DATETIME NULL,
  client_read_at DATETIME NULL,
  merchant_read_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_conversations (stall_id, client_id),
  KEY idx_conversations_client (client_id, last_message_at),
  KEY idx_conversations_stall (stall_id, last_message_at),
  CONSTRAINT fk_conversations_stall FOREIGN KEY (stall_id) REFERENCES stalls(id) ON DELETE CASCADE,
  CONSTRAINT fk_conversations_client FOREIGN KEY (client_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE messages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  conversation_id INT UNSIGNED NOT NULL,
  sender_id INT UNSIGNED NOT NULL,
  kind ENUM('text','photo','voice') NOT NULL DEFAULT 'text',
  body VARCHAR(2000) NULL,
  file_path VARCHAR(255) NULL,                -- photo : dossier WebP public ; vocal : storage/audio (lien signé)
  file_width SMALLINT UNSIGNED NULL,
  file_height SMALLINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  expires_at DATETIME NULL,                   -- NULL = gardé (le client suit l'étal)
  KEY idx_messages_conversation (conversation_id, id),
  KEY idx_messages_expiry (expires_at),
  CONSTRAINT fk_messages_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
  CONSTRAINT fk_messages_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Abonnement payé au mois ou à l'année (réduction annuelle réglable)
ALTER TABLE subscriptions
  ADD COLUMN period ENUM('month','year') NOT NULL DEFAULT 'month' AFTER plan;

-- Paiement sur place : le client paie en ligne les frais (réception, regroupement, service Axi) et la
-- marchandise en espèces au commerçant. La part du commerçant n'entre pas dans le séquestre (« cash »).
ALTER TABLE orders
  ADD COLUMN payment_mode ENUM('online','on_site') NOT NULL DEFAULT 'online' AFTER reception_fee,
  ADD COLUMN service_fee INT UNSIGNED NOT NULL DEFAULT 0 AFTER payment_mode,
  ADD COLUMN stock_restored_at DATETIME NULL AFTER cancelled_at;

ALTER TABLE order_stalls
  MODIFY COLUMN escrow_state ENUM('pending','held','delivered','released','refunded','disputed','cash') NOT NULL DEFAULT 'pending';

INSERT INTO settings (k, v, label, type) VALUES
  ('subscription_yearly_discount', '5', 'Réduction de l''abonnement payé pour un an, % (0 à 50)', 'int'),
  ('free_product_limit', '5', 'Sans abonnement : nombre maximum de produits en vente', 'int'),
  ('message_ttl_hours', '24', 'Messages effacés après ce délai si le client ne suit pas l''étal, heures', 'int');
