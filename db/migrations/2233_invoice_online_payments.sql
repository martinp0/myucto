-- MyÚčto.cz — Online platba faktury přes Stripe Checkout („Zaplatit online").
--
-- Volitelná funkce, výchozí vypnutá (cfg `stripe.enabled` / MYINVOICE_STRIPE_ENABLED).
-- Řádek = jedna Stripe Checkout Session k faktuře. Session platí max. 24 h, proto
-- odkaz v PDF/e-mailu nevede přímo na Stripe, ale na trvalé
-- `/api/public/invoice/{token}/pay`, které při kliknutí použije otevřenou session
-- nebo založí novou. Webhook `checkout.session.completed` podle `checkout_session_id`
-- zaeviduje úhradu (invoice_payments) — podmíněný přechod open→paid je pojistka
-- proti dvojímu zaevidování při opakovaném doručení webhooku.
--
-- Re-run safe: CREATE TABLE IF NOT EXISTS.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS invoice_online_payments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    supplier_id INT UNSIGNED NOT NULL,
    invoice_id BIGINT UNSIGNED NOT NULL,
    provider VARCHAR(20) NOT NULL DEFAULT 'stripe',
    checkout_session_id VARCHAR(255) NOT NULL COMMENT 'Stripe cs_… (test i live)',
    amount DECIMAL(12,2) NOT NULL COMMENT 'částka session v měně faktury',
    currency CHAR(3) NOT NULL,
    status ENUM('open','paid','expired') NOT NULL DEFAULT 'open',
    livemode TINYINT(1) NOT NULL DEFAULT 0,
    payment_intent_id VARCHAR(255) NULL DEFAULT NULL,
    invoice_payment_id BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'invoice_payments.id po úhradě',
    expires_at DATETIME NOT NULL,
    paid_at DATETIME NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_iop_session (checkout_session_id),
    KEY ix_iop_invoice (invoice_id, status),
    KEY ix_iop_supplier (supplier_id),
    CONSTRAINT fk_iop_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE CASCADE,
    CONSTRAINT fk_iop_supplier FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
