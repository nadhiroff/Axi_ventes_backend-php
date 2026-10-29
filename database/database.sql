-- =============================================================================
-- Axi : installation complète de la base de données
-- =============================================================================
-- Fichier généré par « php database/build_sql.php » à partir de database/migrations/.
-- Ne pas modifier à la main.
--
-- INSTALLATION (hébergement sans SSH) :
--   1. Créer une base vide dans le panneau de l'hébergeur (cPanel > Bases de données MySQL),
--      en interclassement utf8mb4_unicode_ci.
--   2. phpMyAdmin > choisir CETTE base > onglet « Importer » > ce fichier > Exécuter.
--   3. Créer le premier administrateur : importer database/premier_admin.sql (voir README).
--
-- MISE À JOUR d'une base déjà installée : ne PAS réimporter ce fichier. Importer seulement
-- les nouveaux fichiers de database/migrations/ absents de la table schema_migrations.
-- =============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE schema_migrations (
  file VARCHAR(190) PRIMARY KEY,
  applied_at DATETIME NOT NULL
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- 001_auth.sql
-- -----------------------------------------------------------------------------
-- Axi : schéma de la base (étape 1 : authentification)
-- Exécution : mysql -u admin -p < database/migrations/001_auth.sql


CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  phone VARCHAR(20) NOT NULL,
  name VARCHAR(100) NOT NULL,
  role ENUM('client','merchant','pro','agent','admin') NOT NULL DEFAULT 'client',
  lang ENUM('fr','fon','yo','en') NOT NULL DEFAULT 'fr',
  status ENUM('active','suspended') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_phone (phone)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS otp_codes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  phone VARCHAR(20) NOT NULL,
  code_hash CHAR(64) NOT NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  KEY idx_otp_phone (phone, id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS auth_tokens (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  device VARCHAR(120) NULL,
  expires_at DATETIME NOT NULL,
  last_used_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_tokens_hash (token_hash),
  KEY idx_tokens_user (user_id),
  CONSTRAINT fk_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT INTO schema_migrations (file, applied_at) VALUES ('001_auth.sql', UTC_TIMESTAMP());

-- -----------------------------------------------------------------------------
-- 002_marche_et_axi_pro.sql
-- -----------------------------------------------------------------------------
-- Axi : marché (sites, allées, étals, produits, commandes), gestion des commerçants et Axi Pro.
-- Reprend docs/sql/002_auth_et_axi_pro.sql du frontend (sauf otp_codes) et ajoute les tables du marché.
-- Montants en FCFA (entiers). Dates en UTC.


-- ---------------------------------------------------------------------------
-- Organisations partenaires (ANaGeM, annonceurs, IMF, études)
-- ---------------------------------------------------------------------------
CREATE TABLE organizations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  type ENUM('anagem','advertiser','mfi','research') NOT NULL,
  status ENUM('active','suspended') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

ALTER TABLE users
  ADD COLUMN organization_id INT UNSIGNED NULL AFTER role,
  ADD CONSTRAINT fk_users_organization FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL;

-- ---------------------------------------------------------------------------
-- Sites et allées : identifiants lisibles, repris tels quels dans l'API
-- ---------------------------------------------------------------------------
CREATE TABLE sites (
  id VARCHAR(30) PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  city VARCHAR(100) NOT NULL DEFAULT 'Cotonou',
  map_url VARCHAR(255) NULL,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE aisles (
  id VARCHAR(30) PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  icon ENUM('fabric','grain','beauty','hardware','parts','jewelry','electronics','other') NOT NULL DEFAULT 'other',
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB;

-- Référentiel produits : "Riz 25kg" et "Sac riz 25 kg" pointent vers la même référence (prix médians)
CREATE TABLE product_refs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  aisle_id VARCHAR(30) NOT NULL,
  name VARCHAR(150) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_product_ref_name (name),
  FOREIGN KEY (aisle_id) REFERENCES aisles(id)
) ENGINE=InnoDB;

-- Prix médians par référence, site et jour, calculés chaque nuit par cron/run.php
CREATE TABLE price_stats_daily (
  product_ref_id INT UNSIGNED NOT NULL,
  site_id VARCHAR(30) NOT NULL,
  day DATE NOT NULL,
  median_price INT UNSIGNED NOT NULL,
  sample_size SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (product_ref_id, site_id, day),
  INDEX idx_site_day (site_id, day),
  FOREIGN KEY (product_ref_id) REFERENCES product_refs(id) ON DELETE CASCADE,
  FOREIGN KEY (site_id) REFERENCES sites(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Étals : un par commerçant, créé et vérifié sur place par un agent
-- ---------------------------------------------------------------------------
CREATE TABLE stalls (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL,
  tagline VARCHAR(160) NOT NULL DEFAULT '',
  site_id VARCHAR(30) NOT NULL,
  aisle_id VARCHAR(30) NOT NULL,
  row_label VARCHAR(30) NULL,               -- « row » dans l'API (ROW est un mot réservé MySQL)
  stand VARCHAR(20) NULL,
  has_physical_place TINYINT(1) NOT NULL DEFAULT 1,
  merchant_type ENUM('retail','wholesale') NOT NULL DEFAULT 'retail',
  map_url VARCHAR(255) NULL,
  verified_at DATETIME NULL,
  verified_by INT UNSIGNED NULL,
  moved_at DATETIME NULL,
  relocation_sent_at DATETIME NULL,
  phone VARCHAR(20) NULL,
  whatsapp VARCHAR(20) NULL,
  accepts_voice_messages TINYINT(1) NOT NULL DEFAULT 0,
  pay_on_delivery TINYINT(1) NOT NULL DEFAULT 0,
  delivery_note VARCHAR(160) NULL,
  is_open TINYINT(1) NOT NULL DEFAULT 1,
  status ENUM('active','closed') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_stalls_user (user_id),
  KEY idx_stalls_aisle (aisle_id, status),
  KEY idx_stalls_site (site_id, status),
  CONSTRAINT fk_stalls_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_stalls_site FOREIGN KEY (site_id) REFERENCES sites(id),
  CONSTRAINT fk_stalls_aisle FOREIGN KEY (aisle_id) REFERENCES aisles(id),
  CONSTRAINT fk_stalls_agent FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- price_amount NULL = « Prix à débattre » ; price_max renseigné = fourchette
CREATE TABLE products (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stall_id INT UNSIGNED NOT NULL,
  product_ref_id INT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  price_amount INT UNSIGNED NULL,
  price_max INT UNSIGNED NULL,
  in_stock TINYINT(1) NOT NULL DEFAULT 1,
  is_today TINYINT(1) NOT NULL DEFAULT 0,
  image_path VARCHAR(255) NULL,             -- dossier contenant thumb.webp et medium.webp
  image_width SMALLINT UNSIGNED NULL,
  image_height SMALLINT UNSIGNED NULL,
  status ENUM('active','deleted') NOT NULL DEFAULT 'active',
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_products_stall (stall_id, status, is_today, updated_at),
  KEY idx_products_ref (product_ref_id),
  CONSTRAINT fk_products_stall FOREIGN KEY (stall_id) REFERENCES stalls(id),
  CONSTRAINT fk_products_ref FOREIGN KEY (product_ref_id) REFERENCES product_refs(id) ON DELETE SET NULL,
  CONSTRAINT fk_products_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE stall_follows (
  user_id INT UNSIGNED NOT NULL,
  stall_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (user_id, stall_id),
  KEY idx_follows_stall (stall_id),
  CONSTRAINT fk_follows_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_follows_stall FOREIGN KEY (stall_id) REFERENCES stalls(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE voice_messages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stall_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  audio_path VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_voice_stall (stall_id, id),
  CONSTRAINT fk_voice_stall FOREIGN KEY (stall_id) REFERENCES stalls(id) ON DELETE CASCADE,
  CONSTRAINT fk_voice_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Commandes : un seul paiement Mobile Money pour plusieurs étals, gardé par Axi
-- ---------------------------------------------------------------------------
CREATE TABLE axi_points (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  address VARCHAR(200) NOT NULL,
  site_id VARCHAR(30) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  CONSTRAINT fk_points_site FOREIGN KEY (site_id) REFERENCES sites(id)
) ENGINE=InnoDB;

CREATE TABLE orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id INT UNSIGNED NOT NULL,
  status ENUM('awaiting_payment','paid','preparing','on_the_way','delivered','cancelled') NOT NULL DEFAULT 'awaiting_payment',
  items_total INT UNSIGNED NOT NULL,
  grouping_fee INT UNSIGNED NOT NULL,
  reception_fee INT UNSIGNED NOT NULL,
  total INT UNSIGNED NOT NULL,
  reception_mode ENUM('delivery','bus','axi_point') NOT NULL,
  reception_detail VARCHAR(255) NOT NULL,
  axi_point_id INT UNSIGNED NULL,
  payment_operator ENUM('mtn','moov','celtiis') NOT NULL,
  payment_phone VARCHAR(20) NOT NULL,
  paid_at DATETIME NULL,
  delivered_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_orders_client (client_id, id),
  KEY idx_orders_status (status, paid_at),
  CONSTRAINT fk_orders_client FOREIGN KEY (client_id) REFERENCES users(id),
  CONSTRAINT fk_orders_point FOREIGN KEY (axi_point_id) REFERENCES axi_points(id)
) ENGINE=InnoDB;

-- Part de la commande pour chaque étal (ce que voit le commerçant)
CREATE TABLE order_stalls (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  stall_id INT UNSIGNED NOT NULL,
  items_total INT UNSIGNED NOT NULL,
  status ENUM('awaiting_payment','to_prepare','ready','picked_up','delivered','cancelled') NOT NULL DEFAULT 'awaiting_payment',
  ready_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_order_stalls (order_id, stall_id),
  KEY idx_order_stalls_stall (stall_id, status),
  CONSTRAINT fk_order_stalls_order FOREIGN KEY (order_id) REFERENCES orders(id),
  CONSTRAINT fk_order_stalls_stall FOREIGN KEY (stall_id) REFERENCES stalls(id)
) ENGINE=InnoDB;

-- Lignes : nom et prix copiés au moment de l'achat
CREATE TABLE order_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_stall_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  unit_price INT UNSIGNED NOT NULL,
  quantity SMALLINT UNSIGNED NOT NULL,
  line_total INT UNSIGNED NOT NULL,
  KEY idx_items_order_stall (order_stall_id),
  CONSTRAINT fk_items_order_stall FOREIGN KEY (order_stall_id) REFERENCES order_stalls(id),
  CONSTRAINT fk_items_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB;

-- held = argent gardé par Axi (séquestre) ; released = versé aux commerçants après réception
CREATE TABLE payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  provider VARCHAR(30) NOT NULL,
  provider_ref VARCHAR(80) NULL,
  operator ENUM('mtn','moov','celtiis') NOT NULL,
  phone VARCHAR(20) NOT NULL,
  amount INT UNSIGNED NOT NULL,
  status ENUM('pending','held','released','failed','refunded') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_payments_order (order_id),
  CONSTRAINT fk_payments_order FOREIGN KEY (order_id) REFERENCES orders(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Commerçant : fichier clients (il lui appartient), messages groupés, abonnement
-- ---------------------------------------------------------------------------
CREATE TABLE merchant_clients (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stall_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  display_name VARCHAR(100) NOT NULL,
  phone VARCHAR(20) NOT NULL,
  kind ENUM('individual','reseller') NOT NULL DEFAULT 'individual',
  orders_count INT UNSIGNED NOT NULL DEFAULT 0,
  last_order_at DATETIME NULL,
  last_follow_up_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_clients_phone (stall_id, phone),
  CONSTRAINT fk_clients_stall FOREIGN KEY (stall_id) REFERENCES stalls(id) ON DELETE CASCADE,
  CONSTRAINT fk_clients_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE broadcasts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stall_id INT UNSIGNED NOT NULL,
  kind ENUM('relocation','arrival','follow_up','segment') NOT NULL,
  channel ENUM('axi','whatsapp') NOT NULL DEFAULT 'axi',
  segment VARCHAR(20) NULL,
  message VARCHAR(500) NOT NULL,
  audio_path VARCHAR(255) NULL,
  recipients INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  KEY idx_broadcasts_stall (stall_id, id),
  CONSTRAINT fk_broadcasts_stall FOREIGN KEY (stall_id) REFERENCES stalls(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE subscriptions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stall_id INT UNSIGNED NOT NULL,
  plan ENUM('retail','wholesale') NOT NULL,
  amount INT UNSIGNED NOT NULL,
  paid_by ENUM('merchant','anagem') NOT NULL DEFAULT 'merchant',
  starts_on DATE NOT NULL,
  ends_on DATE NOT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_subscriptions_stall (stall_id, ends_on),
  CONSTRAINT fk_subscriptions_stall FOREIGN KEY (stall_id) REFERENCES stalls(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Campagnes (annonceurs) : le ciblage reste chez Axi, aucune liste ne sort
-- ---------------------------------------------------------------------------
CREATE TABLE campaigns (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id INT UNSIGNED NOT NULL,
  created_by INT UNSIGNED NOT NULL,
  audience_type ENUM('merchants','clients') NOT NULL,
  aisle_id VARCHAR(30) NULL,
  site_id VARCHAR(30) NULL,
  format ENUM('supplier_offer','sponsored_home') NOT NULL,
  title VARCHAR(150) NULL,
  body VARCHAR(500) NULL,
  target_type ENUM('stall','aisle') NULL,
  target_id VARCHAR(30) NULL,
  url VARCHAR(255) NULL,
  budget INT UNSIGNED NOT NULL,
  status ENUM('pending_review','active','ended','rejected') NOT NULL DEFAULT 'pending_review',
  reviewed_by INT UNSIGNED NULL,
  starts_at DATETIME NULL,
  ends_at DATETIME NULL,
  impressions INT UNSIGNED NOT NULL DEFAULT 0,
  clicks INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_campaign_status (status, starts_at),
  FOREIGN KEY (organization_id) REFERENCES organizations(id),
  FOREIGN KEY (created_by) REFERENCES users(id),
  FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (aisle_id) REFERENCES aisles(id),
  FOREIGN KEY (site_id) REFERENCES sites(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Partage d'historique avec une IMF (accord explicite, révocable) et crédits
-- ---------------------------------------------------------------------------
CREATE TABLE data_sharing_consents (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  merchant_user_id INT UNSIGNED NOT NULL,
  organization_id INT UNSIGNED NOT NULL,
  granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revoked_at DATETIME NULL,
  INDEX idx_consent_active (organization_id, revoked_at),
  FOREIGN KEY (merchant_user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (organization_id) REFERENCES organizations(id)
) ENGINE=InnoDB;

CREATE TABLE credits (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  merchant_user_id INT UNSIGNED NOT NULL,
  organization_id INT UNSIGNED NOT NULL,
  amount INT UNSIGNED NULL,
  status ENUM('new','in_review','granted','declined','repaid') NOT NULL DEFAULT 'new',
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  decided_at DATETIME NULL,
  INDEX idx_credit_org_status (organization_id, status),
  FOREIGN KEY (merchant_user_id) REFERENCES users(id),
  FOREIGN KEY (organization_id) REFERENCES organizations(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Agents : demandes de visite et journal de toutes leurs actions
-- ---------------------------------------------------------------------------
CREATE TABLE agent_visit_requests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  status ENUM('new','scheduled','done','cancelled') NOT NULL DEFAULT 'new',
  assigned_agent_id INT UNSIGNED NULL,
  scheduled_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_visit_status (status, created_at),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (assigned_agent_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE admin_actions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor_id INT UNSIGNED NOT NULL,
  action VARCHAR(60) NOT NULL,
  target_type VARCHAR(30) NOT NULL,
  target_id INT UNSIGNED NULL,
  details JSON NULL,
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL,
  KEY idx_admin_actions_actor (actor_id, id),
  KEY idx_admin_actions_target (target_type, target_id),
  CONSTRAINT fk_admin_actions_actor FOREIGN KEY (actor_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Données de référence
-- ---------------------------------------------------------------------------
INSERT INTO sites (id, name, sort_order) VALUES
  ('yoyo', 'Tokpa Yôyô', 1),
  ('daho', 'Tokpa Dahô', 2),
  ('ouando', 'Ouando', 3),
  ('st-michel', 'Saint-Michel', 4);

INSERT INTO aisles (id, name, icon, sort_order) VALUES
  ('tissus', 'Tissus et pagnes', 'fabric', 1),
  ('vivriers', 'Vivriers en gros', 'grain', 2),
  ('cosmetiques', 'Cosmétiques', 'beauty', 3),
  ('quincaillerie', 'Quincaillerie', 'hardware', 4),
  ('pieces', 'Pièces détachées', 'parts', 5),
  ('bijoux', 'Bijoux et sacs', 'jewelry', 6),
  ('electronique', 'Électronique', 'electronics', 7);

INSERT INTO axi_points (name, address, site_id, sort_order) VALUES
  ('Point Axi Tokpa Yôyô', 'Entrée principale, à côté de la pharmacie', 'yoyo', 1),
  ('Point Axi Tokpa Dahô', 'Parking des grossistes', 'daho', 2);

INSERT INTO schema_migrations (file, applied_at) VALUES ('002_marche_et_axi_pro.sql', UTC_TIMESTAMP());

-- -----------------------------------------------------------------------------
-- 003_auth_email.sql
-- -----------------------------------------------------------------------------
-- Axi : connexion par email et mot de passe (remplace la connexion par code SMS)
-- otp_codes n'est plus utilisée mais elle est conservée.
-- Attention : sur une base qui contient déjà des comptes, renseigner email et password_hash
-- de chaque compte AVANT d'ajouter l'index unique (sinon doublons sur la chaîne vide).


ALTER TABLE users
  MODIFY name VARCHAR(130) NOT NULL,
  ADD COLUMN first_name VARCHAR(60) NOT NULL AFTER phone,
  ADD COLUMN last_name VARCHAR(60) NOT NULL AFTER first_name,
  ADD COLUMN email VARCHAR(190) NOT NULL AFTER last_name,
  ADD COLUMN password_hash VARCHAR(255) NOT NULL AFTER email,
  ADD COLUMN email_verified_at DATETIME NULL AFTER password_hash,
  ADD UNIQUE KEY uq_users_email (email);

-- Codes à 6 chiffres envoyés par email ; seul le HMAC du code est stocké.
-- Seul le code le plus récent d'un couple (email, purpose) est valable.
CREATE TABLE IF NOT EXISTS email_codes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  purpose ENUM('verify_email','reset_password') NOT NULL,
  code_hash CHAR(64) NOT NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  KEY idx_email_codes (email, purpose, created_at)
) ENGINE=InnoDB;

-- Échecs de connexion : 5 par 15 minutes, par email et par adresse IP
CREATE TABLE IF NOT EXISTS login_failures (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_failures_email (email, created_at),
  KEY idx_failures_ip (ip, created_at)
) ENGINE=InnoDB;

INSERT INTO schema_migrations (file, applied_at) VALUES ('003_auth_email.sql', UTC_TIMESTAMP());

-- -----------------------------------------------------------------------------
-- 004_paiement_unique_et_reception.sql
-- -----------------------------------------------------------------------------
-- Axi : un client ne paie jamais deux fois la même commande, et l'argent n'est libéré qu'à la réception.
-- - orders.idempotency_key : clé envoyée par l'application (en-tête Idempotency-Key) ; une même clé
--   renvoie toujours la même commande, même après une coupure réseau ou un double appui.
-- - orders.request_hash : empreinte du panier ; sans clé, le même panier envoyé deux fois en 15 minutes
--   renvoie la commande déjà créée.
-- - payments : un seul paiement par commande, une seule référence par prestataire.


ALTER TABLE orders
  ADD COLUMN idempotency_key VARCHAR(64) NULL AFTER client_id,
  ADD COLUMN request_hash CHAR(64) NULL AFTER idempotency_key,
  ADD UNIQUE KEY uq_orders_idempotency (client_id, idempotency_key),
  ADD KEY idx_orders_request (client_id, request_hash, created_at);

ALTER TABLE payments
  ADD COLUMN reference VARCHAR(40) NULL AFTER provider,
  ADD COLUMN released_at DATETIME NULL AFTER status,
  ADD UNIQUE KEY uq_payments_order (order_id),
  ADD UNIQUE KEY uq_payments_reference (reference),
  ADD UNIQUE KEY uq_payments_provider_ref (provider, provider_ref);

ALTER TABLE order_stalls
  ADD COLUMN picked_up_at DATETIME NULL AFTER ready_at,
  ADD COLUMN delivered_at DATETIME NULL AFTER picked_up_at;

INSERT INTO schema_migrations (file, applied_at) VALUES ('004_paiement_unique_et_reception.sql', UTC_TIMESTAMP());
