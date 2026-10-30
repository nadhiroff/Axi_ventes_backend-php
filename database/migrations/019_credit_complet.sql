-- =============================================================================
-- 019 : crédit de bout en bout : offre de l'IMF, acceptation du commerçant, versement, remboursements
--       (saisis par l'IMF, ou retenus sur les versements Axi si le commerçant l'a accepté), commission d'apport.
--       « granted » veut désormais dire : versé, en cours de remboursement.
-- Base déjà installée : importer CE fichier seul (phpMyAdmin > Importer).
-- =============================================================================
ALTER TABLE credits
  MODIFY status ENUM('new','in_review','offered','accepted','granted','declined','repaid') NOT NULL DEFAULT 'new',
  ADD COLUMN repay_total INT UNSIGNED NULL AFTER amount,
  ADD COLUMN term_months TINYINT UNSIGNED NULL AFTER repay_total,
  ADD COLUMN withhold_pct TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER term_months,
  ADD COLUMN commission INT UNSIGNED NOT NULL DEFAULT 0 AFTER withhold_pct,
  ADD COLUMN repaid_amount INT UNSIGNED NOT NULL DEFAULT 0 AFTER commission,
  ADD COLUMN accepted_at DATETIME NULL AFTER decided_at,
  ADD COLUMN disbursed_at DATETIME NULL AFTER accepted_at,
  ADD COLUMN disbursed_ref VARCHAR(80) NULL AFTER disbursed_at;

CREATE TABLE credit_repayments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  credit_id INT UNSIGNED NOT NULL,
  amount INT UNSIGNED NOT NULL,
  source ENUM('manual','payout') NOT NULL,
  payout_id INT UNSIGNED NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  KEY idx_credit_repayments (credit_id, id),
  CONSTRAINT fk_credit_repayments FOREIGN KEY (credit_id) REFERENCES credits(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT INTO settings (k, v, label, type) VALUES ('credit_commission_rate', '2', 'Commission d''apport d''Axi sur chaque crédit versé, %', 'int');

-- Anciens crédits « accordés » : rien n'a été versé par l'application, ils attendent leur versement
UPDATE credits SET status = 'accepted', repay_total = amount, term_months = 1, accepted_at = decided_at WHERE status = 'granted';
