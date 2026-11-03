<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Payment\Stripe;

use MyInvoice\Action\Payment\StripeWebhookAction;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\InvoicePaymentService;
use MyInvoice\Service\Invoice\InvoicePublicLinkService;
use MyInvoice\Service\Payment\Stripe\StripeApiClient;
use MyInvoice\Service\Payment\Stripe\StripeOnlinePaymentService;
use MyInvoice\Service\Payment\Stripe\StripeWebhookVerifier;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * Checkout Session pro fakturu a zpracování webhooku: založení / znovupoužití
 * session, zápis úhrady jen jednou (Stripe doručuje at-least-once), kontrola
 * částky a měny, expirace a webhook endpoint (404 vypnuto / 400 podpis / 500 retry).
 * Stripe API i InvoicePaymentService jsou mocky; tabulka v in-memory SQLite.
 */
#[AllowMockObjectsWithoutExpectations]
final class StripeOnlinePaymentServiceTest extends TestCase
{
    private const TOKEN = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';
    private const WHSEC = 'whsec_synthetic_test_secret';

    private \PDO $pdo;
    private Connection $db;
    private StripeApiClient&MockObject $api;
    private InvoicePaymentService&MockObject $payments;
    private InvoiceRepository&MockObject $invoices;
    /** @var array<string,mixed> */
    private array $invoice;

    protected function setUp(): void
    {
        $this->pdo = \PDO::connect('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        if ($this->pdo instanceof \Pdo\Sqlite) {
            $this->pdo->createFunction('NOW', static fn (): string => date('Y-m-d H:i:s'), 0);
        }
        $this->pdo->exec("CREATE TABLE invoice_online_payments (
            id INTEGER PRIMARY KEY AUTOINCREMENT, supplier_id INTEGER, invoice_id INTEGER, provider TEXT,
            checkout_session_id TEXT UNIQUE, amount REAL, currency TEXT, status TEXT DEFAULT 'open',
            livemode INTEGER DEFAULT 0, payment_intent_id TEXT NULL, invoice_payment_id INTEGER NULL,
            expires_at TEXT, paid_at TEXT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
        $this->db = new Connection($this->createStub(Config::class));
        (new \ReflectionProperty(Connection::class, 'pdo'))->setValue($this->db, $this->pdo);

        $this->api = $this->createMock(StripeApiClient::class);
        $this->payments = $this->createMock(InvoicePaymentService::class);
        $this->invoices = $this->createMock(InvoiceRepository::class);
        $this->invoice = [
            'id' => 501, 'supplier_id' => 41, 'invoice_type' => 'invoice', 'status' => 'sent',
            'payment_method' => 'bank_transfer', 'currency' => 'CZK', 'amount_to_pay' => 1210.0,
            'paid_total' => 0.0, 'varsymbol' => '2026001', 'language' => 'cs', 'public_token' => self::TOKEN,
        ];
        $this->invoices->method('findByPublicToken')->willReturnCallback(
            fn (string $t): ?array => $t === self::TOKEN ? $this->invoice : null);
    }

    private function service(bool $enabled = true): StripeOnlinePaymentService
    {
        $config = new Config(['stripe' => [
            'enabled' => $enabled, 'secret_key' => 'sk_test_abc123', 'webhook_secret' => self::WHSEC,
        ]]);
        $links = $this->createStub(InvoicePublicLinkService::class);
        $links->method('url')->willReturn('https://ucto.example.test/invoice/' . self::TOKEN);
        return new StripeOnlinePaymentService(
            $config, $this->db, $this->invoices, $links, $this->api, $this->payments,
            $this->createStub(ActivityLogger::class), new NullLogger(),
        );
    }

    /** @return array<string,mixed> */
    private static function session(string $id = 'cs_test_A1', array $over = []): array
    {
        return $over + ['id' => $id, 'url' => 'https://checkout.stripe.com/c/pay/' . $id, 'status' => 'open',
            'livemode' => false, 'expires_at' => time() + 23 * 3600];
    }

    // ── Checkout Session ──────────────────────────────────────────────

    public function testCreatesCheckoutSessionInCzkAndStoresIt(): void
    {
        $captured = null;
        $this->api->expects(self::once())->method('createCheckoutSession')
            ->willReturnCallback(function (array $params, string $key) use (&$captured): array {
                $captured = [$params, $key];
                return self::session();
            });

        $url = $this->service()->checkoutUrlForToken(self::TOKEN);

        self::assertSame('https://checkout.stripe.com/c/pay/cs_test_A1', $url);
        [$params, $key] = $captured;
        self::assertSame('payment', $params['mode']);
        self::assertSame(['card'], $params['payment_method_types'], 'card = karta + Apple Pay + Google Pay');
        self::assertSame('czk', $params['line_items'][0]['price_data']['currency']);
        self::assertSame(121000, $params['line_items'][0]['price_data']['unit_amount']);
        self::assertSame('501', $params['metadata']['myucto_invoice_id']);
        self::assertSame('https://ucto.example.test/invoice/' . self::TOKEN . '?payment=success', $params['success_url']);
        self::assertStringStartsWith('myucto-inv-501-121000-', $key);

        $row = $this->pdo->query('SELECT * FROM invoice_online_payments')->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('cs_test_A1', $row['checkout_session_id']);
        self::assertSame('open', $row['status']);
        self::assertEqualsWithDelta(1210.0, (float) $row['amount'], 0.001);
    }

    public function testReusesOpenSessionForSameAmount(): void
    {
        $this->api->expects(self::once())->method('createCheckoutSession')->willReturn(self::session());
        $this->api->expects(self::once())->method('retrieveCheckoutSession')->with('cs_test_A1')->willReturn(self::session());

        $svc = $this->service();
        $first = $svc->checkoutUrlForToken(self::TOKEN);
        $second = $svc->checkoutUrlForToken(self::TOKEN); // např. náhled odkazu v e-mailovém klientu

        self::assertSame($first, $second);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM invoice_online_payments')->fetchColumn());
    }

    public function testPartialPaymentCreatesNewSessionForRemainingAmount(): void
    {
        $this->api->expects(self::exactly(2))->method('createCheckoutSession')
            ->willReturnOnConsecutiveCalls(self::session('cs_test_A1'), self::session('cs_test_B2'));
        $this->api->expects(self::never())->method('retrieveCheckoutSession');

        $svc = $this->service();
        $svc->checkoutUrlForToken(self::TOKEN);
        $this->invoice['paid_total'] = 210.0;
        self::assertSame('https://checkout.stripe.com/c/pay/cs_test_B2', $svc->checkoutUrlForToken(self::TOKEN));
    }

    public function testDisabledOrPaidInvoiceGetsNoSessionAndNoApiCall(): void
    {
        $this->api->expects(self::never())->method('createCheckoutSession');
        self::assertNull($this->service(enabled: false)->checkoutUrlForToken(self::TOKEN));
        self::assertNull($this->service()->checkoutUrlForToken('f' . substr(self::TOKEN, 1)));
        $this->invoice['status'] = 'paid';
        $this->invoice['paid_total'] = 1210.0;
        self::assertNull($this->service()->checkoutUrlForToken(self::TOKEN));
    }

    // ── Webhook události ──────────────────────────────────────────────

    private function seedOpenSession(string $id = 'cs_test_A1', float $amount = 1210.0): void
    {
        $this->pdo->prepare("INSERT INTO invoice_online_payments
            (supplier_id, invoice_id, provider, checkout_session_id, amount, currency, status, expires_at)
            VALUES (41, 501, 'stripe', ?, ?, 'CZK', 'open', '2099-01-01 00:00:00')")->execute([$id, $amount]);
    }

    /** @return array<string,mixed> */
    private static function completedEvent(array $sessionOver = [], string $type = 'checkout.session.completed'): array
    {
        return ['id' => 'evt_1', 'type' => $type, 'created' => 1_800_000_000, 'data' => ['object' => $sessionOver + [
            'id' => 'cs_test_A1', 'payment_status' => 'paid', 'amount_total' => 121000, 'currency' => 'czk',
            'payment_intent' => 'pi_test_1', 'livemode' => false, 'metadata' => ['myucto_invoice_id' => '501'],
        ]]];
    }

    public function testPaidEventRecordsPaymentExactlyOnce(): void
    {
        $this->seedOpenSession();
        $this->payments->expects(self::once())->method('recordPayment')
            ->with(501, 1210.0, self::anything(), self::callback(static fn (array $o): bool =>
                $o['bank_reference'] === 'stripe:cs_test_A1' && $o['source'] === 'manual'))
            ->willReturn(['payment_id' => 9001, 'became_paid' => true]);

        $svc = $this->service();
        self::assertSame('paid', $svc->handleEvent(self::completedEvent()));
        // Stripe doručuje at-least-once: druhé doručení i async_payment_succeeded nic nezapíšou.
        self::assertSame('already_paid', $svc->handleEvent(self::completedEvent()));
        self::assertSame('already_paid', $svc->handleEvent(self::completedEvent([], 'checkout.session.async_payment_succeeded')));

        $row = $this->pdo->query('SELECT * FROM invoice_online_payments')->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('paid', $row['status']);
        self::assertSame(9001, (int) $row['invoice_payment_id']);
        self::assertSame('pi_test_1', $row['payment_intent_id']);
    }

    public function testMismatchedAmountCurrencyOrInvoiceIsNotRecorded(): void
    {
        $this->seedOpenSession();
        $this->payments->expects(self::never())->method('recordPayment');
        $svc = $this->service();

        self::assertSame('mismatch', $svc->handleEvent(self::completedEvent(['amount_total' => 100])));
        self::assertSame('mismatch', $svc->handleEvent(self::completedEvent(['currency' => 'eur'])));
        self::assertSame('mismatch', $svc->handleEvent(self::completedEvent(['metadata' => ['myucto_invoice_id' => '777']])));
        self::assertSame('open', $this->pdo->query('SELECT status FROM invoice_online_payments')->fetchColumn());
    }

    public function testUnpaidUnknownIgnoredAndExpiredEvents(): void
    {
        $this->seedOpenSession();
        $this->payments->expects(self::never())->method('recordPayment');
        $svc = $this->service();

        self::assertSame('unpaid', $svc->handleEvent(self::completedEvent(['payment_status' => 'unpaid'])));
        self::assertSame('unknown_session', $svc->handleEvent(self::completedEvent(['id' => 'cs_test_OTHER'])));
        self::assertSame('ignored', $svc->handleEvent(['type' => 'charge.refunded', 'data' => ['object' => []]]));
        self::assertSame('expired', $svc->handleEvent(self::completedEvent([], 'checkout.session.expired')));
        self::assertSame('expired', $this->pdo->query('SELECT status FROM invoice_online_payments')->fetchColumn());
    }

    public function testFailedRecordingReleasesClaimSoRetrySucceeds(): void
    {
        $this->seedOpenSession();
        $calls = 0;
        $this->payments->expects(self::exactly(2))->method('recordPayment')
            ->willReturnCallback(function () use (&$calls): array {
                if (++$calls === 1) {
                    throw new \RuntimeException('DB deadlock');
                }
                return ['payment_id' => 9002, 'became_paid' => true];
            });
        $svc = $this->service();

        try {
            $svc->handleEvent(self::completedEvent());
            self::fail('Výjimka má propadnout (webhook vrátí 500 a Stripe zopakuje).');
        } catch (\RuntimeException) {
        }
        self::assertSame('open', $this->pdo->query('SELECT status FROM invoice_online_payments')->fetchColumn());
        self::assertSame('paid', $svc->handleEvent(self::completedEvent()));
    }

    // ── Webhook endpoint ──────────────────────────────────────────────

    private function webhook(StripeOnlinePaymentService $svc, string $payload, string $signature): array
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/public/stripe/webhook')
            ->withHeader('Stripe-Signature', $signature)
            ->withBody((new StreamFactory())->createStream($payload));
        $response = (new StripeWebhookAction($svc, new NullLogger()))($request, (new ResponseFactory())->createResponse());
        $response->getBody()->rewind();
        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    public function testWebhookEndpointIsHiddenWhenDisabled(): void
    {
        $payload = json_encode(self::completedEvent());
        [$status] = $this->webhook($this->service(enabled: false), $payload, StripeWebhookVerifier::sign($payload, self::WHSEC));
        self::assertSame(404, $status);
    }

    public function testWebhookRejectsBadSignature(): void
    {
        $this->seedOpenSession();
        $this->payments->expects(self::never())->method('recordPayment');
        $payload = json_encode(self::completedEvent());
        [$status, $body] = $this->webhook($this->service(), $payload, StripeWebhookVerifier::sign($payload, 'whsec_attacker'));
        self::assertSame(400, $status);
        self::assertSame('open', $this->pdo->query('SELECT status FROM invoice_online_payments')->fetchColumn());
    }

    public function testWebhookMarksInvoicePaidAndReturns500OnFailureForRetry(): void
    {
        $this->seedOpenSession();
        $this->payments->method('recordPayment')->willReturnOnConsecutiveCalls(
            self::throwException(new \RuntimeException('boom')),
            ['payment_id' => 9003, 'became_paid' => true],
        );
        $payload = json_encode(self::completedEvent());
        $sig = StripeWebhookVerifier::sign($payload, self::WHSEC);

        [$status] = $this->webhook($this->service(), $payload, $sig);
        self::assertSame(500, $status);

        [$status, $body] = $this->webhook($this->service(), $payload, $sig);
        self::assertSame(200, $status);
        self::assertSame('paid', $body['result'] ?? ($body['data']['result'] ?? null));
    }
}
