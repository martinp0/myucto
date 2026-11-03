<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payment\Stripe;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\InvoicePaymentService;
use MyInvoice\Service\Invoice\InvoicePublicLinkService;
use Psr\Log\LoggerInterface;

/**
 * Online platba faktury přes Stripe Checkout („Zaplatit online").
 *
 *  - {@see checkoutUrlForToken()} — kliknutí na trvalý odkaz z PDF/e-mailu/web
 *    faktury: použije otevřenou session na stejnou částku, jinak založí novou.
 *    Odkaz otevírají i náhledy v poštovních klientech, proto se session znovu
 *    používá a nevzniká při každém GET nová.
 *  - {@see handleEvent()} — ověřená událost z webhooku: `checkout.session.completed`
 *    / `checkout.session.async_payment_succeeded` s `payment_status = paid`
 *    zaeviduje úhradu, `checkout.session.expired` session uzavře.
 *
 * Platba se eviduje jako `invoice_payments.source = 'manual'` s
 * `bank_reference = 'stripe:{cs_…}'`; enum zdrojů platby se kvůli volitelné
 * integraci nerozšiřuje (navazuje na něj účetnictví i párování).
 */
final class StripeOnlinePaymentService
{
    /** Stripe dovolí expiraci Checkout Session 30 min – 24 h. */
    private const SESSION_TTL_SEC = 23 * 3600;
    /** Session s kratší zbývající platností už zákazníkovi nedáváme. */
    private const REUSE_MIN_REMAINING_SEC = 15 * 60;

    public function __construct(
        private readonly Config $config,
        private readonly Connection $db,
        private readonly InvoiceRepository $invoices,
        private readonly InvoicePublicLinkService $publicLinks,
        private readonly StripeApiClient $stripe,
        private readonly InvoicePaymentService $payments,
        private readonly ActivityLogger $activity,
        private readonly LoggerInterface $logger,
    ) {}

    public function config(): StripeConfig
    {
        return StripeConfig::fromConfig($this->config);
    }

    /**
     * URL Stripe Checkout pro fakturu s daným tokenem web faktury, nebo null,
     * když se online platba nenabízí (zaplaceno, vypnuto, nepodporovaná měna…).
     *
     * @throws StripeException Stripe API selhalo
     */
    public function checkoutUrlForToken(string $publicToken): ?string
    {
        $cfg = $this->config();
        if (!$cfg->isEnabled()) {
            return null;
        }
        $invoice = $this->invoices->findByPublicToken($publicToken);
        if ($invoice === null) {
            return null;
        }
        $amount = OnlinePaymentPolicy::payableAmount($invoice, $cfg);
        if ($amount === null) {
            return null;
        }
        $invoiceId = (int) $invoice['id'];
        $currency = strtoupper((string) $invoice['currency']);

        $reuse = $this->db->pdo()->prepare(
            "SELECT checkout_session_id, amount, currency, expires_at FROM invoice_online_payments
              WHERE invoice_id = ? AND status = 'open' AND expires_at > ?
              ORDER BY id DESC LIMIT 1"
        );
        $reuse->execute([$invoiceId, date('Y-m-d H:i:s', time() + self::REUSE_MIN_REMAINING_SEC)]);
        $open = $reuse->fetch(\PDO::FETCH_ASSOC);
        if ($open !== false
            && abs((float) $open['amount'] - $amount) < 0.005
            && (string) $open['currency'] === $currency) {
            try {
                $session = $this->stripe->retrieveCheckoutSession((string) $open['checkout_session_id']);
                if (($session['status'] ?? '') === 'open' && is_string($session['url'] ?? null)) {
                    return $session['url'];
                }
            } catch (StripeException $e) {
                $this->logger->warning('stripe.session_reuse_failed', ['invoice_id' => $invoiceId, 'error' => $e->getMessage()]);
            }
        }

        $publicUrl = $this->publicLinks->url($publicToken, (int) $invoice['supplier_id']);
        $locale = ($invoice['language'] ?? 'cs') === 'en' ? 'en' : 'cs';
        $label = ($locale === 'en' ? 'Invoice ' : 'Faktura ') . (string) ($invoice['varsymbol'] ?? $invoiceId);
        $expiresAt = time() + self::SESSION_TTL_SEC;
        $metadata = [
            'myucto_invoice_id'  => (string) $invoiceId,
            'myucto_supplier_id' => (string) (int) $invoice['supplier_id'],
            'myucto_varsymbol'   => (string) ($invoice['varsymbol'] ?? ''),
        ];
        $params = [
            'mode'                 => 'payment',
            // `card` zahrnuje i Apple Pay a Google Pay — Checkout je nabídne sám,
            // když je zařízení/prohlížeč podporuje (bez registrace domény).
            'payment_method_types' => ['card'],
            'line_items'           => [[
                'quantity'   => 1,
                'price_data' => [
                    'currency'     => strtolower($currency),
                    'unit_amount'  => OnlinePaymentPolicy::toMinorUnits($amount),
                    'product_data' => ['name' => $label],
                ],
            ]],
            'client_reference_id'  => (string) $invoiceId,
            'metadata'             => $metadata,
            'payment_intent_data'  => [
                'description' => $label,
                'metadata'    => $metadata,
            ],
            'locale'               => $locale,
            'expires_at'           => $expiresAt,
            'success_url'          => $publicUrl . '?payment=success',
            'cancel_url'           => $publicUrl . '?payment=cancel',
        ];
        // Idempotency-Key: dvojklik/souběžné náhledy ve stejné minutě nezaloží dvě session.
        $idempotencyKey = 'myucto-inv-' . $invoiceId . '-' . OnlinePaymentPolicy::toMinorUnits($amount) . '-' . intdiv(time(), 60);
        $session = $this->stripe->createCheckoutSession($params, $idempotencyKey);
        $sessionId = (string) ($session['id'] ?? '');
        $url = $session['url'] ?? null;
        if (preg_match('/^cs_[A-Za-z0-9_]+$/', $sessionId) !== 1 || !is_string($url) || !str_starts_with($url, 'https://')) {
            throw new StripeException('Stripe nevrátilo platnou Checkout Session.');
        }

        // Stejný Idempotency-Key vrací stejnou session — druhý záznam nezakládat.
        $known = $this->db->pdo()->prepare('SELECT 1 FROM invoice_online_payments WHERE checkout_session_id = ?');
        $known->execute([$sessionId]);
        if ($known->fetchColumn() === false) {
            try {
                $this->db->pdo()->prepare(
                    'INSERT INTO invoice_online_payments
                        (supplier_id, invoice_id, provider, checkout_session_id, amount, currency, status, livemode, expires_at)
                     VALUES (?, ?, \'stripe\', ?, ?, ?, \'open\', ?, ?)'
                )->execute([
                    (int) $invoice['supplier_id'], $invoiceId, $sessionId, $amount, $currency,
                    !empty($session['livemode']) ? 1 : 0,
                    date('Y-m-d H:i:s', (int) ($session['expires_at'] ?? $expiresAt)),
                ]);
            } catch (\PDOException $e) {
                if ((string) $e->getCode() !== '23000') {
                    throw $e; // jen souběžný zápis téže session (UNIQUE) je v pořádku
                }
            }
        }

        return $url;
    }

    /**
     * Zpracuje OVĚŘENOU událost webhooku ({@see StripeWebhookVerifier}).
     * Neznámé typy se ignorují (Stripe dostane 200, jinak by je opakoval).
     *
     * @param array<string,mixed> $event
     * @return string výsledek pro log/odpověď: paid|already_paid|expired|ignored|unknown_session|mismatch|unpaid
     */
    public function handleEvent(array $event): string
    {
        $type = (string) ($event['type'] ?? '');
        $session = (array) ($event['data']['object'] ?? []);
        if (!in_array($type, [
            'checkout.session.completed',
            'checkout.session.async_payment_succeeded',
            'checkout.session.expired',
        ], true)) {
            return 'ignored';
        }
        $sessionId = (string) ($session['id'] ?? '');
        $stmt = $this->db->pdo()->prepare('SELECT * FROM invoice_online_payments WHERE checkout_session_id = ?');
        $stmt->execute([$sessionId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            // Session jiné aplikace na stejném Stripe účtu — nic neděláme.
            return 'unknown_session';
        }
        $rowId = (int) $row['id'];

        if ($type === 'checkout.session.expired') {
            $this->db->pdo()->prepare("UPDATE invoice_online_payments SET status = 'expired' WHERE id = ? AND status = 'open'")
                ->execute([$rowId]);
            return 'expired';
        }
        if (($session['payment_status'] ?? '') !== 'paid') {
            // completed s odloženou platbou — počkáme na async_payment_succeeded.
            return 'unpaid';
        }

        // Session musí sedět na to, co jsme zakládali: faktura, měna i částka.
        $invoiceId = (int) $row['invoice_id'];
        $metaInvoice = (string) ($session['metadata']['myucto_invoice_id'] ?? '');
        $amountMinor = (int) ($session['amount_total'] ?? -1);
        $currency = strtoupper((string) ($session['currency'] ?? ''));
        if ($metaInvoice !== (string) $invoiceId
            || $currency !== (string) $row['currency']
            || $amountMinor !== OnlinePaymentPolicy::toMinorUnits((float) $row['amount'])) {
            $this->logger->error('stripe.session_mismatch', ['session' => $sessionId, 'invoice_id' => $invoiceId]);
            return 'mismatch';
        }

        $pdo = $this->db->pdo();
        // Podmíněný přechod open|expired → paid: opakované doručení webhooku
        // (Stripe doručuje at-least-once) úhradu nezaeviduje podruhé.
        $claim = $pdo->prepare(
            "UPDATE invoice_online_payments SET status = 'paid', paid_at = NOW(), payment_intent_id = ?
              WHERE id = ? AND status <> 'paid'"
        );
        $claim->execute([is_string($session['payment_intent'] ?? null) ? $session['payment_intent'] : null, $rowId]);
        if ($claim->rowCount() !== 1) {
            return 'already_paid';
        }

        $paidOn = (new \DateTimeImmutable('@' . (int) ($event['created'] ?? time())))
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
            ->format('Y-m-d');
        try {
            $result = $this->payments->recordPayment($invoiceId, (float) $row['amount'], $paidOn, [
                'source'         => 'manual',
                'bank_reference' => 'stripe:' . $sessionId,
                'note'           => 'Online platba Stripe (karta / Apple Pay / Google Pay)',
            ]);
        } catch (\Throwable $e) {
            // Vrátit nárok, ať opakovaný webhook úhradu zkusí znovu.
            $pdo->prepare("UPDATE invoice_online_payments SET status = ?, paid_at = NULL WHERE id = ? AND invoice_payment_id IS NULL")
                ->execute([(string) $row['status'] === 'expired' ? 'expired' : 'open', $rowId]);
            throw $e;
        }
        $pdo->prepare('UPDATE invoice_online_payments SET invoice_payment_id = ? WHERE id = ?')
            ->execute([(int) $result['payment_id'], $rowId]);

        $this->activity->log('invoice.paid_online_stripe', null, 'invoice', $invoiceId, [
            'checkout_session_id' => $sessionId,
            'amount'              => (float) $row['amount'],
            'currency'            => (string) $row['currency'],
            'livemode'            => !empty($session['livemode']),
            'payment_id'          => (int) $result['payment_id'],
            'became_paid'         => (bool) ($result['became_paid'] ?? false),
        ], null, null, (int) $row['supplier_id']);

        return 'paid';
    }
}
