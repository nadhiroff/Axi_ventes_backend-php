-- =============================================================================
-- Axi : installation complète de la base de données
-- =============================================================================
-- Fichier généré par « php database/build_sql.php » à partir de database/migrations/.
-- Ne pas modifier à la main.
--
-- INSTALLATION (hébergement sans SSH) :
--   1. Créer une base VIDE dans le panneau de l'hébergeur, interclassement utf8mb4_unicode_ci.
--   2. phpMyAdmin > choisir CETTE base > onglet « Importer » > ce fichier > Exécuter.
--   3. Créer le premier compte partenaire (agent Axi) : php database/premier_partenaire.php (voir README).
--
-- MISE À JOUR d'une base déjà installée : ne PAS réimporter ce fichier ; importer seulement les nouveaux
-- fichiers de database/migrations/ absents de la table schema_migrations.
-- =============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';
CREATE TABLE schema_migrations (
  file VARCHAR(190) PRIMARY KEY,
  applied_at DATETIME NOT NULL
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- 001_schema.sql
-- -----------------------------------------------------------------------------
-- =============================================================================
-- Axi : schéma complet de la base (3 rôles : client, commerçant, partenaire)
-- Montants en FCFA (entiers). Dates en UTC. Aucune authentification par téléphone ni SMS.
-- =============================================================================


-- -----------------------------------------------------------------------------
-- Réglages métier modifiables par un agent Axi (hypothèses de la note de cadrage)
-- -----------------------------------------------------------------------------
CREATE TABLE settings (
  k VARCHAR(60) PRIMARY KEY,
  v VARCHAR(255) NOT NULL,
  label VARCHAR(160) NOT NULL,
  type ENUM('int','percent','bool','text') NOT NULL DEFAULT 'int',
  updated_by INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- Comptes : client, commerçant, partenaire (sous-type = type de son organisation)
-- -----------------------------------------------------------------------------
CREATE TABLE organizations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  type ENUM('axi','anagem','advertiser','mfi') NOT NULL,   -- agent Axi, ANaGeM, annonceur, IMF
  status ENUM('active','suspended') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  first_name VARCHAR(60) NOT NULL,
  last_name VARCHAR(60) NOT NULL,
  name VARCHAR(130) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  email_verified_at DATETIME NULL,
  phone VARCHAR(20) NULL,                 -- simple contact (Mobile Money, WhatsApp), jamais pour se connecter
  role ENUM('client','merchant','partner') NOT NULL DEFAULT 'client',
  organization_id INT UNSIGNED NULL,      -- obligatoire pour un partenaire
  lang ENUM('fr','en','fon','yo') NOT NULL DEFAULT 'fr',
  status ENUM('active','suspended','deleted') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  UNIQUE KEY uq_users_email (email),
  UNIQUE KEY uq_users_phone (phone),
  KEY idx_users_role (role, status),
  CONSTRAINT fk_users_organization FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL
) ENGINE=InnoDB;

ALTER TABLE settings ADD CONSTRAINT fk_settings_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL;

CREATE TABLE auth_tokens (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,           -- sha256 du jeton ; le jeton n'est jamais stocké
  device VARCHAR(120) NULL,
  ip VARCHAR(45) NULL,
  expires_at DATETIME NOT NULL,
  last_used_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_tokens_hash (token_hash),
  KEY idx_tokens_user (user_id),
  CONSTRAINT fk_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Codes à 6 chiffres envoyés par EMAIL (HMAC) ; seul le dernier code d'un couple (email, purpose) compte
CREATE TABLE email_codes (
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

-- Historique des connexions (réussies et échouées) : sécurité et limite de 5 échecs par 15 minutes
CREATE TABLE login_events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  email VARCHAR(190) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  device VARCHAR(120) NULL,
  channel ENUM('app','web') NOT NULL DEFAULT 'app',
  success TINYINT(1) NOT NULL,
  reason VARCHAR(40) NULL,
  created_at DATETIME NOT NULL,
  KEY idx_login_email (email, created_at),
  KEY idx_login_ip (ip, created_at),
  KEY idx_login_user (user_id, id),
  CONSTRAINT fk_login_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Paramètres de l'utilisateur (thème, notifications, infos mémorisées, plafond de données)
CREATE TABLE user_settings (
  user_id INT UNSIGNED PRIMARY KEY,
  theme ENUM('system','light','dark') NOT NULL DEFAULT 'system',
  currency CHAR(3) NOT NULL DEFAULT 'XOF',
  date_format ENUM('dd/mm/yyyy','yyyy-mm-dd') NOT NULL DEFAULT 'dd/mm/yyyy',
  notifications JSON NULL,               -- {"order":{"email":true,"app":true}, ...}
  default_address VARCHAR(255) NULL,
  preferred_point_id INT UNSIGNED NULL,
  preferred_operator ENUM('mtn','moov','celtiis') NULL,
  preferred_payment_phone VARCHAR(20) NULL,
  data_cap_mb INT UNSIGNED NOT NULL DEFAULT 1500,
  data_month CHAR(7) NULL,
  data_used_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_user_settings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Notifications dans l'application (et copie par email selon les préférences)
CREATE TABLE notifications (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  type ENUM('order','payment','reminder','promotion','arrival','account') NOT NULL,
  title VARCHAR(160) NOT NULL,
  body VARCHAR(600) NOT NULL,
  data JSON NULL,
  read_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  KEY idx_notifications_user (user_id, id),
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- Marché : sites, allées (sous-marchés gérés en base), référentiel produits
-- -----------------------------------------------------------------------------
CREATE TABLE sites (
  id VARCHAR(30) PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  city VARCHAR(100) NOT NULL DEFAULT 'Cotonou',
  map_url VARCHAR(255) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE aisles (
  id VARCHAR(30) PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  icon ENUM('fabric','grain','beauty','hardware','parts','jewelry','electronics','other') NOT NULL DEFAULT 'other',
  active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE product_refs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  aisle_id VARCHAR(30) NOT NULL,
  name VARCHAR(150) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_product_ref_name (name),
  FOREIGN KEY (aisle_id) REFERENCES aisles(id)
) ENGINE=InnoDB;

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

-- -----------------------------------------------------------------------------
-- Étals et produits
-- -----------------------------------------------------------------------------
CREATE TABLE stalls (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL,
  tagline VARCHAR(160) NOT NULL DEFAULT '',
  site_id VARCHAR(30) NOT NULL,
  aisle_id VARCHAR(30) NOT NULL,
  row_label VARCHAR(30) NULL,               -- « row » dans l'API
  stand VARCHAR(20) NULL,
  has_physical_place TINYINT(1) NOT NULL DEFAULT 1,
  merchant_type ENUM('retail','wholesale') NOT NULL DEFAULT 'retail',
  map_url VARCHAR(255) NULL,
  photo_path VARCHAR(255) NULL,
  photo_width SMALLINT UNSIGNED NULL,
  photo_height SMALLINT UNSIGNED NULL,
  verified_at DATETIME NULL,                -- badge « vérifié par un agent Axi »
  verified_by INT UNSIGNED NULL,
  verification_photo_path VARCHAR(255) NULL,-- photo prise par l'agent à l'emplacement réel
  moved_at DATETIME NULL,
  relocation_sent_at DATETIME NULL,
  phone VARCHAR(20) NULL,
  whatsapp VARCHAR(20) NULL,
  accepts_voice_messages TINYINT(1) NOT NULL DEFAULT 0,
  pay_on_delivery TINYINT(1) NOT NULL DEFAULT 0,
  delivery_note VARCHAR(160) NULL,
  is_open TINYINT(1) NOT NULL DEFAULT 1,
  status ENUM('active','suspended','closed') NOT NULL DEFAULT 'active',
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

-- price_amount NULL = « Prix à débattre » ; status draft = brouillon (produit dicté en vocal, à compléter)
CREATE TABLE products (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stall_id INT UNSIGNED NOT NULL,
  product_ref_id INT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  price_amount INT UNSIGNED NULL,
  price_max INT UNSIGNED NULL,
  in_stock TINYINT(1) NOT NULL DEFAULT 1,
  is_today TINYINT(1) NOT NULL DEFAULT 0,
  audio_path VARCHAR(255) NULL,
  status ENUM('draft','active','deleted') NOT NULL DEFAULT 'active',
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_products_stall (stall_id, status, is_today, updated_at),
  KEY idx_products_ref (product_ref_id),
  CONSTRAINT fk_products_stall FOREIGN KEY (stall_id) REFERENCES stalls(id),
  CONSTRAINT fk_products_ref FOREIGN KEY (product_ref_id) REFERENCES product_refs(id) ON DELETE SET NULL,
  CONSTRAINT fk_products_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Photos d'un produit (galerie) : dossier contenant thumb.webp et medium.webp, URL immuable
CREATE TABLE product_images (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  path VARCHAR(255) NOT NULL,
  width SMALLINT UNSIGNED NOT NULL,
  height SMALLINT UNSIGNED NOT NULL,
  position TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  KEY idx_images_product (product_id, position),
  CONSTRAINT fk_images_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
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

CREATE TABLE favorites (
  user_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (user_id, product_id),
  CONSTRAINT fk_favorites_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_favorites_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE product_views (
  user_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  viewed_at DATETIME NOT NULL,
  PRIMARY KEY (user_id, product_id),
  KEY idx_views_user (user_id, viewed_at),
  CONSTRAINT fk_views_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_views_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE voice_messages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stall_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  audio_path VARCHAR(255) NOT NULL,
  listened_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  KEY idx_voice_stall (stall_id, id),
  CONSTRAINT fk_voice_stall FOREIGN KEY (stall_id) REFERENCES stalls(id) ON DELETE CASCADE,
  CONSTRAINT fk_voice_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Demande de vérification de l'étal sur place par un agent Axi
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

-- -----------------------------------------------------------------------------
-- Commandes, paiement unique multi-étals et séquestre
-- -----------------------------------------------------------------------------
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
  idempotency_key VARCHAR(64) NULL,         -- même clé = même commande (jamais deux débits)
  request_hash CHAR(64) NULL,               -- empreinte du panier (double appui sans clé)
  status ENUM('awaiting_payment','paid','preparing','on_the_way','delivered','disputed','refunded','cancelled') NOT NULL DEFAULT 'awaiting_payment',
  items_total INT UNSIGNED NOT NULL,
  grouping_fee INT UNSIGNED NOT NULL,
  reception_fee INT UNSIGNED NOT NULL,
  commission_total INT UNSIGNED NOT NULL DEFAULT 0,
  total INT UNSIGNED NOT NULL,              -- payé par le client (articles + regroupement + réception)
  reception_mode ENUM('delivery','bus','axi_point') NOT NULL,
  reception_detail VARCHAR(255) NOT NULL,
  axi_point_id INT UNSIGNED NULL,
  payment_operator ENUM('mtn','moov','celtiis') NULL,
  payment_phone VARCHAR(20) NULL,
  paid_at DATETIME NULL,
  delivered_at DATETIME NULL,
  cancelled_at DATETIME NULL,
  reminder_sent_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_orders_idempotency (client_id, idempotency_key),
  KEY idx_orders_request (client_id, request_hash, created_at),
  KEY idx_orders_client (client_id, id),
  KEY idx_orders_status (status, paid_at),
  CONSTRAINT fk_orders_client FOREIGN KEY (client_id) REFERENCES users(id),
  CONSTRAINT fk_orders_point FOREIGN KEY (axi_point_id) REFERENCES axi_points(id)
) ENGINE=InnoDB;

-- Part de chaque étal = ligne interne du séquestre
-- escrow_state : pending → held (payé, bloqué) → delivered → released (au commerçant) | refunded | disputed
CREATE TABLE order_stalls (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  stall_id INT UNSIGNED NOT NULL,
  items_total INT UNSIGNED NOT NULL,
  commission INT UNSIGNED NOT NULL DEFAULT 0,
  net_amount INT UNSIGNED NOT NULL,         -- dû au commerçant
  refunded_amount INT UNSIGNED NOT NULL DEFAULT 0,
  status ENUM('awaiting_payment','to_prepare','ready','picked_up','delivered','cancelled') NOT NULL DEFAULT 'awaiting_payment',
  escrow_state ENUM('pending','held','delivered','released','refunded','disputed') NOT NULL DEFAULT 'pending',
  payout_id INT UNSIGNED NULL,
  ready_at DATETIME NULL,
  picked_up_at DATETIME NULL,
  delivered_at DATETIME NULL,
  released_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_order_stalls (order_id, stall_id),
  KEY idx_order_stalls_stall (stall_id, status),
  KEY idx_order_stalls_escrow (escrow_state, payout_id),
  CONSTRAINT fk_order_stalls_order FOREIGN KEY (order_id) REFERENCES orders(id),
  CONSTRAINT fk_order_stalls_stall FOREIGN KEY (stall_id) REFERENCES stalls(id)
) ENGINE=InnoDB;

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

CREATE TABLE subscriptions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stall_id INT UNSIGNED NOT NULL,
  plan ENUM('retail','wholesale') NOT NULL,
  amount INT UNSIGNED NOT NULL,
  paid_by ENUM('merchant','anagem','axi') NOT NULL DEFAULT 'merchant',
  status ENUM('pending','active','cancelled') NOT NULL DEFAULT 'pending',
  starts_on DATE NOT NULL,
  ends_on DATE NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  KEY idx_subscriptions_stall (stall_id, status, ends_on),
  CONSTRAINT fk_subscriptions_stall FOREIGN KEY (stall_id) REFERENCES stalls(id)
) ENGINE=InnoDB;

-- Paiement chez la passerelle (FedaPay ou KkiaPay) : un seul par commande ou par abonnement
-- status : pending → paid (argent reçu ; bloqué chez Axi pour une commande) | failed | cancelled | expired
--          paid → refunded | partially_refunded
CREATE TABLE payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  purpose ENUM('order','subscription') NOT NULL,
  order_id INT UNSIGNED NULL,
  subscription_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NOT NULL,
  provider VARCHAR(20) NOT NULL,            -- fedapay | kkiapay
  reference VARCHAR(40) NOT NULL,           -- référence Axi fixe (merchant_reference / partnerId)
  provider_ref VARCHAR(80) NULL,            -- identifiant de la transaction chez la passerelle
  payment_url VARCHAR(600) NULL,
  operator ENUM('mtn','moov','celtiis') NULL,
  phone VARCHAR(20) NULL,
  amount INT UNSIGNED NOT NULL,             -- calculé par le serveur, jamais fourni par le client
  refunded_amount INT UNSIGNED NOT NULL DEFAULT 0,
  status ENUM('pending','paid','failed','cancelled','expired','refunded','partially_refunded') NOT NULL DEFAULT 'pending',
  gateway_status VARCHAR(40) NULL,
  failure_reason VARCHAR(255) NULL,
  paid_at DATETIME NULL,
  checked_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_payments_reference (reference),
  UNIQUE KEY uq_payments_order (order_id),
  UNIQUE KEY uq_payments_subscription (subscription_id),
  UNIQUE KEY uq_payments_provider_ref (provider, provider_ref),
  KEY idx_payments_user (user_id, id),
  KEY idx_payments_status (status, created_at),
  CONSTRAINT fk_payments_order FOREIGN KEY (order_id) REFERENCES orders(id),
  CONSTRAINT fk_payments_subscription FOREIGN KEY (subscription_id) REFERENCES subscriptions(id),
  CONSTRAINT fk_payments_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- Journal de chaque changement d'état (paiement, séquestre, commande) : qui, quand, avant, après
CREATE TABLE payment_events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  payment_id INT UNSIGNED NULL,
  order_id INT UNSIGNED NULL,
  order_stall_id INT UNSIGNED NULL,
  actor_type ENUM('system','client','merchant','partner','gateway') NOT NULL,
  actor_id INT UNSIGNED NULL,
  event VARCHAR(40) NOT NULL,
  old_state VARCHAR(30) NULL,
  new_state VARCHAR(30) NULL,
  gateway_ref VARCHAR(80) NULL,
  amount INT UNSIGNED NULL,
  details JSON NULL,
  created_at DATETIME NOT NULL,
  KEY idx_events_payment (payment_id, id),
  KEY idx_events_order (order_id, id)
) ENGINE=InnoDB;

-- Webhooks reçus : un même événement reçu deux fois n'est traité qu'une fois
CREATE TABLE webhook_events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  provider VARCHAR(20) NOT NULL,
  event_key VARCHAR(190) NOT NULL,
  payload MEDIUMTEXT NOT NULL,
  result VARCHAR(80) NULL,
  received_at DATETIME NOT NULL,
  processed_at DATETIME NULL,
  UNIQUE KEY uq_webhook_event (provider, event_key)
) ENGINE=InnoDB;

CREATE TABLE refunds (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  payment_id INT UNSIGNED NOT NULL,
  order_stall_id INT UNSIGNED NULL,
  amount INT UNSIGNED NOT NULL,
  reason VARCHAR(255) NOT NULL,
  status ENUM('pending','succeeded','failed','simulated') NOT NULL DEFAULT 'pending',
  provider_ref VARCHAR(80) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  KEY idx_refunds_payment (payment_id),
  CONSTRAINT fk_refunds_payment FOREIGN KEY (payment_id) REFERENCES payments(id)
) ENGINE=InnoDB;

CREATE TABLE disputes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  order_stall_id INT UNSIGNED NULL,
  client_id INT UNSIGNED NOT NULL,
  reason VARCHAR(1000) NOT NULL,
  status ENUM('open','resolved_refund','resolved_release','rejected') NOT NULL DEFAULT 'open',
  resolution VARCHAR(1000) NULL,
  resolved_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  resolved_at DATETIME NULL,
  KEY idx_disputes_status (status, id),
  CONSTRAINT fk_disputes_order FOREIGN KEY (order_id) REFERENCES orders(id),
  CONSTRAINT fk_disputes_client FOREIGN KEY (client_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- Versements aux commerçants (couche séparée ; simulés en sandbox)
CREATE TABLE payouts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stall_id INT UNSIGNED NOT NULL,
  amount INT UNSIGNED NOT NULL,
  phone VARCHAR(20) NULL,
  provider VARCHAR(20) NOT NULL,
  provider_ref VARCHAR(80) NULL,
  status ENUM('pending','simulated','paid','failed') NOT NULL DEFAULT 'pending',
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  processed_at DATETIME NULL,
  KEY idx_payouts_stall (stall_id, id),
  CONSTRAINT fk_payouts_stall FOREIGN KEY (stall_id) REFERENCES stalls(id)
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- Commerçant : fichier clients (il lui appartient), messages groupés
-- -----------------------------------------------------------------------------
CREATE TABLE merchant_clients (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stall_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  display_name VARCHAR(100) NOT NULL,
  phone VARCHAR(20) NULL,
  email VARCHAR(190) NULL,
  kind ENUM('individual','reseller') NOT NULL DEFAULT 'individual',
  note VARCHAR(300) NULL,
  orders_count INT UNSIGNED NOT NULL DEFAULT 0,
  total_spent INT UNSIGNED NOT NULL DEFAULT 0,
  last_order_at DATETIME NULL,
  last_follow_up_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_clients_user (stall_id, user_id),
  UNIQUE KEY uq_clients_phone (stall_id, phone),
  CONSTRAINT fk_clients_stall FOREIGN KEY (stall_id) REFERENCES stalls(id) ON DELETE CASCADE,
  CONSTRAINT fk_clients_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Messages groupés : envoyés par email et notification dans l'app ; lien WhatsApp proposé au commerçant
CREATE TABLE broadcasts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stall_id INT UNSIGNED NOT NULL,
  kind ENUM('relocation','arrival','follow_up','segment') NOT NULL,
  segment VARCHAR(20) NULL,
  message VARCHAR(500) NOT NULL,
  audio_path VARCHAR(255) NULL,
  recipients INT UNSIGNED NOT NULL DEFAULT 0,
  emailed INT UNSIGNED NOT NULL DEFAULT 0,
  notified INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  KEY idx_broadcasts_stall (stall_id, id),
  CONSTRAINT fk_broadcasts_stall FOREIGN KEY (stall_id) REFERENCES stalls(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- Partenaires : campagnes, consentements, crédits
-- -----------------------------------------------------------------------------
-- Formats : sponsored_home (bannière d'accueil), featured_stall (étal mis en avant),
-- sponsored_aisle (catégorie sponsorisée), supplier_offer (offre aux commerçants).
-- Règle : aucune publicité sur la page d'un étal.
CREATE TABLE campaigns (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id INT UNSIGNED NOT NULL,
  created_by INT UNSIGNED NOT NULL,
  audience_type ENUM('merchants','clients') NOT NULL,
  aisle_id VARCHAR(30) NULL,
  site_id VARCHAR(30) NULL,
  format ENUM('sponsored_home','featured_stall','sponsored_aisle','supplier_offer') NOT NULL,
  title VARCHAR(150) NOT NULL,
  body VARCHAR(500) NOT NULL DEFAULT '',
  target_stall_id INT UNSIGNED NULL,
  url VARCHAR(255) NULL,
  image_path VARCHAR(255) NULL,
  budget INT UNSIGNED NOT NULL,
  status ENUM('pending_review','active','ended','rejected') NOT NULL DEFAULT 'pending_review',
  review_note VARCHAR(300) NULL,
  reviewed_by INT UNSIGNED NULL,
  starts_at DATETIME NULL,
  ends_at DATETIME NULL,
  impressions INT UNSIGNED NOT NULL DEFAULT 0,
  clicks INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_campaign_status (status, format),
  FOREIGN KEY (organization_id) REFERENCES organizations(id),
  FOREIGN KEY (created_by) REFERENCES users(id),
  FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (aisle_id) REFERENCES aisles(id),
  FOREIGN KEY (site_id) REFERENCES sites(id),
  FOREIGN KEY (target_stall_id) REFERENCES stalls(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Consentement explicite du commerçant (date, périmètre, révocable) avant tout partage avec une IMF
CREATE TABLE data_sharing_consents (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  merchant_user_id INT UNSIGNED NOT NULL,
  organization_id INT UNSIGNED NOT NULL,
  scope VARCHAR(160) NOT NULL DEFAULT 'Historique des ventes Axi (montants mensuels, ancienneté)',
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
  amount_requested INT UNSIGNED NULL,
  amount INT UNSIGNED NULL,                 -- montant accordé
  status ENUM('new','in_review','granted','declined','repaid') NOT NULL DEFAULT 'new',
  note VARCHAR(300) NULL,
  decided_by INT UNSIGNED NULL,
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  decided_at DATETIME NULL,
  INDEX idx_credit_org_status (organization_id, status),
  FOREIGN KEY (merchant_user_id) REFERENCES users(id),
  FOREIGN KEY (organization_id) REFERENCES organizations(id)
) ENGINE=InnoDB;

-- Journal des actions des partenaires (web) : qui, quoi, quand
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

-- =============================================================================
-- Données de référence (modifiables ensuite dans l'espace web partenaire)
-- =============================================================================
INSERT INTO settings (k, v, label, type) VALUES
  ('grouping_fee_per_stall', '300', 'Frais de regroupement par étal (à partir de 2 étals), FCFA', 'int'),
  ('fee_delivery', '1500', 'Frais de livraison Cotonou, FCFA', 'int'),
  ('fee_bus', '2500', 'Frais d''expédition par car, FCFA', 'int'),
  ('fee_axi_point', '500', 'Frais de retrait au point Axi, FCFA', 'int'),
  ('commission_rate', '2', 'Commission Axi sur les ventes payées en ligne, % (0 = désactivée, sinon 2 à 4)', 'percent'),
  ('subscription_retail', '2000', 'Abonnement mensuel détaillant (connexion incluse), FCFA', 'int'),
  ('subscription_wholesale', '5000', 'Abonnement mensuel grossiste (connexion incluse), FCFA', 'int'),
  ('auto_release_days', '7', 'Libération automatique au commerçant, jours après la remise au coursier', 'int'),
  ('unpaid_expiry_hours', '24', 'Annulation d''une commande jamais payée, heures', 'int'),
  ('payment_reminder_minutes', '60', 'Relance par email d''un paiement en attente, minutes', 'int'),
  ('min_group_size', '5', 'Anonymat Axi Pro : nombre minimum d''étals par statistique', 'int'),
  ('min_campaign_budget', '50000', 'Budget minimum d''une campagne, FCFA', 'int'),
  ('loyal_min_orders', '3', 'Client fidèle : nombre minimum de commandes', 'int'),
  ('follow_up_days', '30', 'Client à relancer : jours sans achat', 'int'),
  ('anagem_partnership', '0', 'Afficher « Service en ligne des marchés · ANaGeM » sur l''accueil', 'bool'),
  ('city_name', 'Cotonou', 'Ville affichée sur l''accueil', 'text');

INSERT INTO organizations (id, name, type) VALUES (1, 'Axi', 'axi');

INSERT INTO sites (id, name, sort_order) VALUES
  ('yoyo', 'Tokpa Yôyô', 1),
  ('daho', 'Tokpa Dahô', 2),
  ('ouando', 'Ouando', 3),
  ('st-michel', 'Saint-Michel', 4),
  ('godomey', 'Godomey-Gare', 5),
  ('arzeke', 'Arzèkè', 6);

INSERT INTO aisles (id, name, icon, sort_order) VALUES
  ('tissus', 'Tissus et pagnes', 'fabric', 1),
  ('vivriers', 'Vivriers', 'grain', 2),
  ('quincaillerie', 'Quincaillerie', 'hardware', 3),
  ('cosmetiques', 'Cosmétiques', 'beauty', 4),
  ('pieces', 'Pièces détachées', 'parts', 5),
  ('electronique', 'Électronique', 'electronics', 6),
  ('bijoux', 'Bijoux et maroquinerie', 'jewelry', 7);

INSERT INTO axi_points (name, address, site_id, sort_order) VALUES
  ('Point Axi Tokpa Yôyô', 'Entrée principale', 'yoyo', 1),
  ('Point Axi Tokpa Dahô', 'Parking des grossistes', 'daho', 2);

INSERT INTO schema_migrations (file, applied_at) VALUES ('001_schema.sql', UTC_TIMESTAMP());

-- -----------------------------------------------------------------------------
-- 002_commercants_messagerie_stock.sql
-- -----------------------------------------------------------------------------
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

INSERT INTO schema_migrations (file, applied_at) VALUES ('002_commercants_messagerie_stock.sql', UTC_TIMESTAMP());

-- -----------------------------------------------------------------------------
-- 003_messagerie_lu_en_ligne.sql
-- -----------------------------------------------------------------------------
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

INSERT INTO schema_migrations (file, applied_at) VALUES ('003_messagerie_lu_en_ligne.sql', UTC_TIMESTAMP());

-- -----------------------------------------------------------------------------
-- 004_reponses_galerie_devises.sql
-- -----------------------------------------------------------------------------
-- =============================================================================
-- 004 : réponse à un message, galerie de photos de l'étal, taux de change (euro, dollar).
-- Base déjà installée : importer CE fichier seul (phpMyAdmin > Importer).
-- =============================================================================

ALTER TABLE messages ADD COLUMN reply_to_id INT UNSIGNED NULL AFTER file_height;

-- Photos supplémentaires de l'étal (la première photo reste stalls.photo_path), 8 au plus
CREATE TABLE stall_photos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stall_id INT UNSIGNED NOT NULL,
  path VARCHAR(255) NOT NULL,
  width SMALLINT UNSIGNED NOT NULL,
  height SMALLINT UNSIGNED NOT NULL,
  position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  KEY idx_stall_photos (stall_id, position),
  CONSTRAINT fk_stall_photos_stall FOREIGN KEY (stall_id) REFERENCES stalls(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Taux réels : l'euro est fixe (1 € = 655,957 F CFA, parité officielle) ; le dollar est relu
-- automatiquement (open.er-api.com) au plus toutes les 12 h. Valeur de départ, remplacée au premier appel.
INSERT INTO settings (k, v, label, type) VALUES
  ('rate_xof_per_usd', '600', 'Taux du dollar en F CFA (mis à jour automatiquement)', 'text'),
  ('rate_updated_at', '2000-01-01 00:00:00', 'Dernière mise à jour du taux du dollar (UTC)', 'text');

INSERT INTO schema_migrations (file, applied_at) VALUES ('004_reponses_galerie_devises.sql', UTC_TIMESTAMP());

-- -----------------------------------------------------------------------------
-- 005_coches_par_message.sql
-- -----------------------------------------------------------------------------
-- =============================================================================
-- 005 : coches ✓✓ fiables — dernier message reçu et lu de chaque côté (par numéro, pas par heure).
-- Base déjà installée : importer CE fichier seul (phpMyAdmin > Importer).
-- =============================================================================
ALTER TABLE conversations
  ADD COLUMN client_read_msg_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER merchant_delivered_at,
  ADD COLUMN merchant_read_msg_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER client_read_msg_id,
  ADD COLUMN client_delivered_msg_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER merchant_read_msg_id,
  ADD COLUMN merchant_delivered_msg_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER client_delivered_msg_id;

INSERT INTO schema_migrations (file, applied_at) VALUES ('005_coches_par_message.sql', UTC_TIMESTAMP());

-- -----------------------------------------------------------------------------
-- 006_profil_signalements_annonces.sql
-- -----------------------------------------------------------------------------
-- =============================================================================
-- 006 : présentation publique du profil, suppression d'un message « pour moi », signalements,
--       blocage d'accès avec motif, annonces d'arrivage écrites.
-- Base déjà installée : importer CE fichier seul (phpMyAdmin > Importer).
-- =============================================================================

-- « À propos » visible des personnes avec qui l'on échange ; motif affiché quand l'accès est bloqué
ALTER TABLE users
  ADD COLUMN bio VARCHAR(300) NULL AFTER avatar_visibility,
  ADD COLUMN suspended_reason VARCHAR(300) NULL AFTER status;

-- Message supprimé « pour moi » : il reste chez l'autre personne
CREATE TABLE message_hidden (
  user_id INT UNSIGNED NOT NULL,
  message_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (user_id, message_id),
  CONSTRAINT fk_hidden_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_hidden_message FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Signalement d'un profil : examiné par un agent Axi (bloquer l'accès ou classer)
CREATE TABLE reports (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reporter_id INT UNSIGNED NOT NULL,
  target_user_id INT UNSIGNED NOT NULL,
  conversation_id INT UNSIGNED NULL,
  reason ENUM('spam','fraud','insult','fake','other') NOT NULL,
  details VARCHAR(1000) NULL,
  status ENUM('open','blocked','dismissed') NOT NULL DEFAULT 'open',
  handled_by INT UNSIGNED NULL,
  handled_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  KEY idx_reports_status (status, id),
  KEY idx_reports_target (target_user_id),
  CONSTRAINT fk_reports_reporter FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_reports_target FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_reports_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Annonce d'arrivage écrite (photo facultative), visible 7 jours sur la page de l'étal ;
-- pour un commerçant abonné, aussi en petite annonce sur l'accueil des clients
CREATE TABLE stall_announcements (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stall_id INT UNSIGNED NOT NULL,
  body VARCHAR(500) NOT NULL,
  photo_path VARCHAR(255) NULL,
  photo_width SMALLINT UNSIGNED NULL,
  photo_height SMALLINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  KEY idx_announcements_stall (stall_id, expires_at),
  KEY idx_announcements_expiry (expires_at),
  CONSTRAINT fk_announcements_stall FOREIGN KEY (stall_id) REFERENCES stalls(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT INTO schema_migrations (file, applied_at) VALUES ('006_profil_signalements_annonces.sql', UTC_TIMESTAMP());

-- -----------------------------------------------------------------------------
-- 007_abonnes_dans_fichier_clients.sql
-- -----------------------------------------------------------------------------
-- =============================================================================
-- 007 : les personnes qui suivent un étal apparaissent dans « Mes clients » du commerçant.
-- Base déjà installée : importer CE fichier seul (phpMyAdmin > Importer).
-- =============================================================================
INSERT IGNORE INTO merchant_clients (stall_id, user_id, display_name, email, created_at)
SELECT f.stall_id, u.id, CONCAT(u.first_name, ' ', LEFT(u.last_name, 1), '.'), u.email, f.created_at
FROM stall_follows f JOIN users u ON u.id = f.user_id
WHERE u.status = 'active';

INSERT INTO schema_migrations (file, applied_at) VALUES ('007_abonnes_dans_fichier_clients.sql', UTC_TIMESTAMP());

-- -----------------------------------------------------------------------------
-- 008_emails_aux_commercants.sql
-- -----------------------------------------------------------------------------
-- =============================================================================
-- 008 : email envoyé à un commerçant depuis la page de son étal (son adresse reste cachée au client).
-- Base déjà installée : importer CE fichier seul (phpMyAdmin > Importer).
-- =============================================================================
CREATE TABLE stall_emails (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stall_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  subject VARCHAR(150) NOT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_stall_emails_user (user_id, created_at),
  CONSTRAINT fk_stall_emails_stall FOREIGN KEY (stall_id) REFERENCES stalls(id) ON DELETE CASCADE,
  CONSTRAINT fk_stall_emails_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT INTO schema_migrations (file, applied_at) VALUES ('008_emails_aux_commercants.sql', UTC_TIMESTAMP());

-- -----------------------------------------------------------------------------
-- 009_description_produit.sql
-- -----------------------------------------------------------------------------
-- =============================================================================
-- 009 : description d'un produit (matière, taille, couleur, origine…), visible sur sa fiche.
-- Base déjà installée : importer CE fichier seul (phpMyAdmin > Importer).
-- =============================================================================
ALTER TABLE products ADD COLUMN description VARCHAR(1000) NULL AFTER name;

INSERT INTO schema_migrations (file, applied_at) VALUES ('009_description_produit.sql', UTC_TIMESTAMP());
