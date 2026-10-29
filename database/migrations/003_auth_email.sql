-- Axi : connexion par email et mot de passe (remplace la connexion par code SMS)
-- otp_codes n'est plus utilisée mais elle est conservée.
-- Attention : sur une base qui contient déjà des comptes, renseigner email et password_hash
-- de chaque compte AVANT d'ajouter l'index unique (sinon doublons sur la chaîne vide).

SET NAMES utf8mb4;

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
