-- Axi : schéma du marché (étape 2 : étals, commandes, paiements, commerçants, Axi Pro)
-- À exécuter une seule fois, après 001_auth.sql :
--   mysql -u admin -p < database/migrations/002_marche.sql
-- (ou onglet « Importer » de phpMyAdmin)
-- Montants en FCFA (entiers). Dates en UTC.

SET NAMES utf8mb4;

USE axi;

ALTER TABLE users ADD COLUMN org_name VARCHAR(120) NULL AFTER name;

-- Sites physiques : Dantokpa, Ouando, Tokpa Yôyô...
CREATE TABLE IF NOT EXISTS markets (
  id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(40) NOT NULL,
  name VARCHAR(80) NOT NULL,
  city VARCHAR(80) NULL,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_markets_slug (slug)
) ENGINE=InnoDB;

-- Allées (sous-marchés par famille de produits)
CREATE TABLE IF NOT EXISTS aisles (
  id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(40) NOT NULL,
  name VARCHAR(80) NOT NULL,
  icon VARCHAR(40) NULL,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_aisles_slug (slug)
) ENGINE=InnoDB;

-- Étal numérique : un étal par commerçant
CREATE TABLE IF NOT EXISTS stalls (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  merchant_id INT UNSIGNED NOT NULL,
  market_id SMALLINT UNSIGNED NOT NULL,
  aisle_id SMALLINT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL,
  description VARCHAR(500) NULL,
  photo_path VARCHAR(255) NULL,
  row_label VARCHAR(20) NULL,
  stall_number VARCHAR(20) NULL,
  contact_phone VARCHAR(20) NULL,
  whatsapp VARCHAR(20) NULL,
  merchant_type ENUM('retail','wholesale') NOT NULL DEFAULT 'retail',
  place_status ENUM('holder','subtenant','no_place') NOT NULL DEFAULT 'holder',
  verified_at DATETIME NULL,
  verified_by INT UNSIGNED NULL,
  verification_photo_path VARCHAR(255) NULL,
  moved_at DATETIME NULL,
  status ENUM('active','hidden') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_stalls_merchant (merchant_id),
  KEY idx_stalls_aisle (aisle_id, market_id, status),
  KEY idx_stalls_market (market_id, status),
  CONSTRAINT fk_stalls_merchant FOREIGN KEY (merchant_id) REFERENCES users(id),
  CONSTRAINT fk_stalls_market FOREIGN KEY (market_id) REFERENCES markets(id),
  CONSTRAINT fk_stalls_aisle FOREIGN KEY (aisle_id) REFERENCES aisles(id),
  CONSTRAINT fk_stalls_agent FOREIGN KEY (verified_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- Historique des déménagements (nouvelle adresse envoyée aux clients)
CREATE TABLE IF NOT EXISTS stall_moves (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stall_id INT UNSIGNED NOT NULL,
  from_market_id SMALLINT UNSIGNED NOT NULL,
  from_row VARCHAR(20) NULL,
  from_number VARCHAR(20) NULL,
  to_market_id SMALLINT UNSIGNED NOT NULL,
  to_row VARCHAR(20) NULL,
  to_number VARCHAR(20) NULL,
  recipients_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  KEY idx_moves_stall (stall_id, id),
  CONSTRAINT fk_moves_stall FOREIGN KEY (stall_id) REFERENCES stalls(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Référentiel de produits comparables (prix médians d'Axi Pro)
CREATE TABLE IF NOT EXISTS catalog_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  aisle_id SMALLINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  unit VARCHAR(40) NOT NULL,
  KEY idx_catalog_aisle (aisle_id),
  CONSTRAINT fk_catalog_aisle FOREIGN KEY (aisle_id) REFERENCES aisles(id)
) ENGINE=InnoDB;

-- price : prix ferme pour la commande en ligne ; price_max : fourchette affichée (facultatif)
CREATE TABLE IF NOT EXISTS products (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stall_id INT UNSIGNED NOT NULL,
  catalog_item_id INT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  description VARCHAR(500) NULL,
  unit VARCHAR(40) NULL,
  price INT UNSIGNED NOT NULL,
  price_max INT UNSIGNED NULL,
  wholesale_price INT UNSIGNED NULL,
  wholesale_min_qty SMALLINT UNSIGNED NULL,
  photo_path VARCHAR(255) NULL,
  audio_path VARCHAR(255) NULL,
  stock_status ENUM('available','low','out') NOT NULL DEFAULT 'available',
  daily_date DATE NULL,
  status ENUM('active','archived') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_products_stall (stall_id, status, daily_date),
  KEY idx_products_catalog (catalog_item_id),
  CONSTRAINT fk_products_stall FOREIGN KEY (stall_id) REFERENCES stalls(id),
  CONSTRAINT fk_products_catalog FOREIGN KEY (catalog_item_id) REFERENCES catalog_items(id)
) ENGINE=InnoDB;

-- Étals suivis par un client
CREATE TABLE IF NOT EXISTS follows (
  user_id INT UNSIGNED NOT NULL,
  stall_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (user_id, stall_id),
  KEY idx_follows_stall (stall_id),
  CONSTRAINT fk_follows_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_follows_stall FOREIGN KEY (stall_id) REFERENCES stalls(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Promotions sponsorisées et campagnes créées dans Axi Pro
-- placement home = accueil client ; merchant = espace « Mon argent » du commerçant
CREATE TABLE IF NOT EXISTS promotions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_id INT UNSIGNED NULL,
  sponsor_name VARCHAR(100) NOT NULL,
  title VARCHAR(120) NOT NULL,
  body VARCHAR(300) NULL,
  image_path VARCHAR(255) NULL,
  placement ENUM('home','merchant') NOT NULL DEFAULT 'home',
  target_type ENUM('none','aisle','stall','product','url') NOT NULL DEFAULT 'none',
  target_id INT UNSIGNED NULL,
  target_url VARCHAR(255) NULL,
  audience_market_id SMALLINT UNSIGNED NULL,
  audience_aisle_id SMALLINT UNSIGNED NULL,
  audience_merchant_type ENUM('retail','wholesale') NULL,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  budget INT UNSIGNED NULL,
  status ENUM('pending','active','paused','rejected') NOT NULL DEFAULT 'pending',
  impressions INT UNSIGNED NOT NULL DEFAULT 0,
  clicks INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  KEY idx_promotions_live (placement, status, starts_at, ends_at),
  CONSTRAINT fk_promotions_owner FOREIGN KEY (owner_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- Modes de livraison : coursier (home), expédition par car (bus), point Axi (pickup)
CREATE TABLE IF NOT EXISTS delivery_options (
  id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  mode ENUM('home','bus','pickup') NOT NULL,
  label VARCHAR(80) NOT NULL,
  city VARCHAR(80) NULL,
  address VARCHAR(255) NULL,
  fee INT UNSIGNED NOT NULL,
  eta_label VARCHAR(40) NULL,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS cart_items (
  user_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  quantity SMALLINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (user_id, product_id),
  CONSTRAINT fk_cart_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_cart_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Commande client : un seul paiement pour plusieurs étals
CREATE TABLE IF NOT EXISTS orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ref VARCHAR(12) NULL,
  client_id INT UNSIGNED NOT NULL,
  subtotal INT UNSIGNED NOT NULL,
  grouping_fee INT UNSIGNED NOT NULL,
  delivery_fee INT UNSIGNED NOT NULL,
  total INT UNSIGNED NOT NULL,
  refund_due INT UNSIGNED NOT NULL DEFAULT 0,
  delivery_option_id SMALLINT UNSIGNED NOT NULL,
  delivery_address VARCHAR(255) NULL,
  contact_phone VARCHAR(20) NOT NULL,
  note VARCHAR(300) NULL,
  status ENUM('pending_payment','paid','completed','cancelled') NOT NULL DEFAULT 'pending_payment',
  paid_at DATETIME NULL,
  received_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_orders_ref (ref),
  KEY idx_orders_client (client_id, id),
  KEY idx_orders_status (status, paid_at),
  CONSTRAINT fk_orders_client FOREIGN KEY (client_id) REFERENCES users(id),
  CONSTRAINT fk_orders_delivery FOREIGN KEY (delivery_option_id) REFERENCES delivery_options(id)
) ENGINE=InnoDB;

-- Part de la commande pour chaque étal (ce que voit le commerçant)
CREATE TABLE IF NOT EXISTS stall_orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  stall_id INT UNSIGNED NOT NULL,
  subtotal INT UNSIGNED NOT NULL,
  commission INT UNSIGNED NOT NULL DEFAULT 0,
  net_amount INT UNSIGNED NOT NULL,
  status ENUM('pending_payment','new','confirmed','ready','received','cancelled') NOT NULL DEFAULT 'pending_payment',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_stall_orders (order_id, stall_id),
  KEY idx_stall_orders_stall (stall_id, status),
  CONSTRAINT fk_stall_orders_order FOREIGN KEY (order_id) REFERENCES orders(id),
  CONSTRAINT fk_stall_orders_stall FOREIGN KEY (stall_id) REFERENCES stalls(id)
) ENGINE=InnoDB;

-- Lignes de commande : nom et prix copiés au moment de l'achat
CREATE TABLE IF NOT EXISTS order_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stall_order_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  unit VARCHAR(40) NULL,
  unit_price INT UNSIGNED NOT NULL,
  quantity SMALLINT UNSIGNED NOT NULL,
  line_total INT UNSIGNED NOT NULL,
  KEY idx_items_stall_order (stall_order_id),
  CONSTRAINT fk_items_stall_order FOREIGN KEY (stall_order_id) REFERENCES stall_orders(id),
  CONSTRAINT fk_items_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS subscriptions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stall_id INT UNSIGNED NOT NULL,
  plan ENUM('retail','wholesale','anagem') NOT NULL,
  amount INT UNSIGNED NOT NULL,
  paid_by ENUM('merchant','anagem') NOT NULL DEFAULT 'merchant',
  data_included TINYINT(1) NOT NULL DEFAULT 1,
  payment_id INT UNSIGNED NULL,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_subscriptions_stall (stall_id, ends_at),
  CONSTRAINT fk_subscriptions_stall FOREIGN KEY (stall_id) REFERENCES stalls(id)
) ENGINE=InnoDB;

-- Paiements Mobile Money. held = gardé par Axi (séquestre) ; released = libéré au commerçant
CREATE TABLE IF NOT EXISTS payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  purpose ENUM('order','subscription') NOT NULL,
  order_id INT UNSIGNED NULL,
  stall_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NOT NULL,
  provider ENUM('fedapay','kkiapay','simulation') NOT NULL,
  provider_ref VARCHAR(64) NULL,
  amount INT UNSIGNED NOT NULL,
  status ENUM('pending','held','released','succeeded','failed','refunded') NOT NULL DEFAULT 'pending',
  paid_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_payments_ref (provider, provider_ref),
  KEY idx_payments_order (order_id),
  CONSTRAINT fk_payments_order FOREIGN KEY (order_id) REFERENCES orders(id),
  CONSTRAINT fk_payments_stall FOREIGN KEY (stall_id) REFERENCES stalls(id),
  CONSTRAINT fk_payments_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- Fichier clients du commerçant (clients Axi et clients ajoutés à la main)
CREATE TABLE IF NOT EXISTS merchant_customers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stall_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  name VARCHAR(100) NOT NULL,
  phone VARCHAR(20) NOT NULL,
  customer_type ENUM('retail','wholesale') NOT NULL DEFAULT 'retail',
  city VARCHAR(80) NULL,
  note VARCHAR(300) NULL,
  orders_count INT UNSIGNED NOT NULL DEFAULT 0,
  total_spent INT UNSIGNED NOT NULL DEFAULT 0,
  last_order_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_customers_phone (stall_id, phone),
  KEY idx_customers_type (stall_id, customer_type),
  KEY idx_customers_last (stall_id, last_order_at),
  CONSTRAINT fk_customers_stall FOREIGN KEY (stall_id) REFERENCES stalls(id) ON DELETE CASCADE,
  CONSTRAINT fk_customers_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Messages groupés : déménagement, arrivage, relance
CREATE TABLE IF NOT EXISTS broadcasts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stall_id INT UNSIGNED NOT NULL,
  kind ENUM('move','arrival','reminder') NOT NULL,
  message VARCHAR(300) NOT NULL,
  audio_path VARCHAR(255) NULL,
  product_id INT UNSIGNED NULL,
  app_count INT UNSIGNED NOT NULL DEFAULT 0,
  sms_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  KEY idx_broadcasts_stall (stall_id, id),
  CONSTRAINT fk_broadcasts_stall FOREIGN KEY (stall_id) REFERENCES stalls(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- File d'attente SMS, vidée par cron/run.php
CREATE TABLE IF NOT EXISTS sms_queue (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  phone VARCHAR(20) NOT NULL,
  message VARCHAR(480) NOT NULL,
  broadcast_id INT UNSIGNED NULL,
  status ENUM('queued','sent','failed') NOT NULL DEFAULT 'queued',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  sent_at DATETIME NULL,
  KEY idx_sms_status (status, id)
) ENGINE=InnoDB;

-- Notifications dans l'application
CREATE TABLE IF NOT EXISTS notifications (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  type VARCHAR(30) NOT NULL,
  title VARCHAR(120) NOT NULL,
  body VARCHAR(480) NOT NULL,
  data TEXT NULL,
  read_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  KEY idx_notifications_user (user_id, id),
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Retraits demandés par le commerçant sur son argent disponible
CREATE TABLE IF NOT EXISTS payouts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stall_id INT UNSIGNED NOT NULL,
  amount INT UNSIGNED NOT NULL,
  phone VARCHAR(20) NOT NULL,
  status ENUM('requested','paid','rejected') NOT NULL DEFAULT 'requested',
  created_at DATETIME NOT NULL,
  processed_at DATETIME NULL,
  KEY idx_payouts_stall (stall_id, id),
  CONSTRAINT fk_payouts_stall FOREIGN KEY (stall_id) REFERENCES stalls(id)
) ENGINE=InnoDB;

-- Offres de crédit des partenaires (IMF) et crédits stock des fournisseurs
CREATE TABLE IF NOT EXISTS credit_offers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  partner_name VARCHAR(100) NOT NULL,
  kind ENUM('cash','stock') NOT NULL,
  title VARCHAR(120) NOT NULL,
  description VARCHAR(500) NULL,
  supplier_name VARCHAR(100) NULL,
  min_amount INT UNSIGNED NOT NULL,
  max_amount INT UNSIGNED NOT NULL,
  max_months TINYINT UNSIGNED NOT NULL,
  rate_label VARCHAR(60) NULL,
  sponsored TINYINT(1) NOT NULL DEFAULT 0,
  audience_aisle_id SMALLINT UNSIGNED NULL,
  active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS credit_requests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stall_id INT UNSIGNED NOT NULL,
  offer_id INT UNSIGNED NULL,
  amount INT UNSIGNED NOT NULL,
  duration_months TINYINT UNSIGNED NOT NULL,
  purpose VARCHAR(300) NULL,
  share_history TINYINT(1) NOT NULL,
  consent_at DATETIME NOT NULL,
  status ENUM('submitted','sent','approved','rejected','repaid') NOT NULL DEFAULT 'submitted',
  approved_amount INT UNSIGNED NULL,
  decided_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  KEY idx_credit_stall (stall_id, id),
  CONSTRAINT fk_credit_stall FOREIGN KEY (stall_id) REFERENCES stalls(id),
  CONSTRAINT fk_credit_offer FOREIGN KEY (offer_id) REFERENCES credit_offers(id)
) ENGINE=InnoDB;
