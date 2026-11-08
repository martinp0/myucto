-- Návrh změny bankovního spojení firmy. Token sem smí jen vložit čekající
-- řádek. Sloupce `currencies` (číslo účtu, IBAN, QR) se z tohoto návrhu
-- nezmění, dokud správce firmy návrh neschválí ve webovém rozhraní.
--
-- Příznak tokenu je ve výchozím stavu vypnutý a zápis účtu neotvírá.

ALTER TABLE api_tokens
  ADD COLUMN IF NOT EXISTS allow_bank_account_propose TINYINT(1) NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS bank_account_change_proposals (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  supplier_id INT UNSIGNED NOT NULL,
  currency_id INT UNSIGNED NOT NULL,
  api_token_id BIGINT UNSIGNED NULL,
  requested_by_user_id BIGINT UNSIGNED NULL,
  token_prefix VARCHAR(16) NOT NULL,
  client_ip VARCHAR(45) NULL,
  status ENUM('pending','approved','rejected','expired','stale','withdrawn') NOT NULL DEFAULT 'pending',
  proposed_fields VARCHAR(80) NOT NULL,
  fingerprint CHAR(64) NOT NULL,
  account_number VARCHAR(30) NULL,
  bank_code VARCHAR(10) NULL,
  bank_name VARCHAR(120) NULL,
  iban VARCHAR(34) NULL,
  bic VARCHAR(11) NULL,
  baseline_account_number VARCHAR(30) NULL,
  baseline_bank_code VARCHAR(10) NULL,
  baseline_bank_name VARCHAR(120) NULL,
  baseline_iban VARCHAR(34) NULL,
  baseline_bic VARCHAR(11) NULL,
  decision_user_id BIGINT UNSIGNED NULL,
  decision_reason VARCHAR(200) NULL,
  expires_at DATETIME NOT NULL,
  decided_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_bacp_supplier_status (supplier_id, status, created_at),
  KEY idx_bacp_token_created (api_token_id, created_at),
  KEY idx_bacp_currency_status (currency_id, status),
  CONSTRAINT fk_bacp_supplier FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE,
  CONSTRAINT fk_bacp_token FOREIGN KEY (api_token_id) REFERENCES api_tokens (id) ON DELETE SET NULL,
  CONSTRAINT fk_bacp_requester FOREIGN KEY (requested_by_user_id) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_bacp_decider FOREIGN KEY (decision_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
