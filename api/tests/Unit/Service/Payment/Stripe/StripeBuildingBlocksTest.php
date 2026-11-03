<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Payment\Stripe;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Payment\Stripe\OnlinePaymentPolicy;
use MyInvoice\Service\Payment\Stripe\StripeApiClient;
use MyInvoice\Service\Payment\Stripe\StripeConfig;
use MyInvoice\Service\Payment\Stripe\StripeException;
use MyInvoice\Service\Payment\Stripe\StripeWebhookVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Stavební kameny online platby „Zaplatit online" (Stripe Checkout): ověření
 * podpisu webhooku, konfigurace (výchozí vypnuto), politika „co lze zaplatit"
 * a HTTP klient bez SDK. Žádné volání skutečného Stripe — klíče jsou syntetické
 * testovací (sk_test_…), HTTP jde přes Guzzle MockHandler.
 */
final class StripeBuildingBlocksTest extends TestCase
{
    private const SECRET = 'whsec_synthetic_test_secret';

    // ── Webhook podpis ────────────────────────────────────────────────

    public function testValidSignatureReturnsDecodedEvent(): void
    {
        $payload = '{"id":"evt_1","type":"checkout.session.completed","data":{"object":{"id":"cs_test_1"}}}';
        $header = StripeWebhookVerifier::sign($payload, self::SECRET, 1_800_000_000);

        $event = StripeWebhookVerifier::verify($payload, $header, self::SECRET, 300, 1_800_000_100);

        self::assertSame('checkout.session.completed', $event['type']);
        self::assertSame('cs_test_1', $event['data']['object']['id']);
    }

    public function testAnyOfMultipleV1SignaturesIsAccepted(): void
    {
        $payload = '{"type":"x","data":{"object":{}}}';
        $good = StripeWebhookVerifier::sign($payload, self::SECRET, 1_800_000_000);
        $header = 't=1800000000,v1=' . str_repeat('0', 64) . ',' . substr($good, strpos($good, 'v1='));

        self::assertSame('x', StripeWebhookVerifier::verify($payload, $header, self::SECRET, 300, 1_800_000_000)['type']);
    }

    /** @return iterable<string,array{string,string,int}> */
    public static function rejectedSignatures(): iterable
    {
        $payload = '{"type":"x","data":{"object":{}}}';
        yield 'cizí klíč' => [$payload, StripeWebhookVerifier::sign($payload, 'whsec_other', 1_800_000_000), 1_800_000_000];
        yield 'změněné tělo' => ['{"type":"y","data":{"object":{}}}', StripeWebhookVerifier::sign($payload, self::SECRET, 1_800_000_000), 1_800_000_000];
        yield 'staré razítko (replay)' => [$payload, StripeWebhookVerifier::sign($payload, self::SECRET, 1_800_000_000), 1_800_000_301];
        yield 'chybí hlavička' => [$payload, '', 1_800_000_000];
        yield 'jen v0 podpis' => [$payload, 't=1800000000,v0=abc', 1_800_000_000];
        yield 'nejde o událost' => ['{"foo":1}', StripeWebhookVerifier::sign('{"foo":1}', self::SECRET, 1_800_000_000), 1_800_000_000];
        yield 'nečitelné tělo' => ['nope', StripeWebhookVerifier::sign('nope', self::SECRET, 1_800_000_000), 1_800_000_000];
    }

    #[DataProvider('rejectedSignatures')]
    public function testInvalidWebhookIsRejected(string $payload, string $header, int $now): void
    {
        $this->expectException(StripeException::class);
        StripeWebhookVerifier::verify($payload, $header, self::SECRET, 300, $now);
    }

    public function testEmptySecretNeverVerifies(): void
    {
        $payload = '{"type":"x","data":{"object":{}}}';
        $this->expectException(StripeException::class);
        StripeWebhookVerifier::verify($payload, StripeWebhookVerifier::sign($payload, ''), '');
    }

    // ── Konfigurace ───────────────────────────────────────────────────

    public function testDisabledByDefault(): void
    {
        $cfg = StripeConfig::fromConfig(new Config([]));
        self::assertFalse($cfg->isEnabled());
        self::assertSame(['CZK'], $cfg->currencies);
    }

    /** @return iterable<string,array{array<string,mixed>,bool}> */
    public static function enablement(): iterable
    {
        $ok = ['enabled' => true, 'secret_key' => 'sk_test_abc123', 'webhook_secret' => 'whsec_abc'];
        yield 'vše nastaveno' => [$ok, true];
        yield 'restricted key' => [['secret_key' => 'rk_live_abc123'] + $ok, true];
        yield 'enabled=false' => [['enabled' => false] + $ok, false];
        yield 'chybí secret key' => [['secret_key' => ''] + $ok, false];
        yield 'publishable key místo secret' => [['secret_key' => 'pk_test_abc'] + $ok, false];
        yield 'chybí webhook secret' => [['webhook_secret' => ''] + $ok, false];
    }

    /** @param array<string,mixed> $stripe */
    #[DataProvider('enablement')]
    public function testEnabledOnlyWithFlagAndBothSecrets(array $stripe, bool $expected): void
    {
        self::assertSame($expected, StripeConfig::fromConfig(new Config(['stripe' => $stripe]))->isEnabled());
    }

    public function testCurrenciesFromCsvAreNormalized(): void
    {
        $cfg = StripeConfig::fromConfig(new Config(['stripe' => ['currencies' => 'czk, eur,xx']]));
        self::assertSame(['CZK', 'EUR'], $cfg->currencies);
        self::assertTrue($cfg->supportsCurrency('eur'));
        self::assertFalse($cfg->supportsCurrency('USD'));
    }

    public function testTestModeDetection(): void
    {
        self::assertTrue((new StripeConfig(true, 'sk_test_x', 'whsec_x', ['CZK']))->isTestMode());
        self::assertFalse((new StripeConfig(true, 'sk_live_x', 'whsec_x', ['CZK']))->isTestMode());
    }

    // ── Politika: co a kolik lze zaplatit online ──────────────────────

    private static function enabledConfig(): StripeConfig
    {
        return new StripeConfig(true, 'sk_test_abc', 'whsec_abc', ['CZK']);
    }

    /** @return array<string,mixed> */
    private static function invoice(array $over = []): array
    {
        return $over + [
            'invoice_type' => 'invoice', 'status' => 'sent', 'payment_method' => 'bank_transfer',
            'currency' => 'CZK', 'amount_to_pay' => 1210.0, 'paid_total' => 0.0,
        ];
    }

    public function testPayableAmountIsRemainingBalance(): void
    {
        self::assertSame(1210.0, OnlinePaymentPolicy::payableAmount(self::invoice(), self::enabledConfig()));
        self::assertSame(710.0, OnlinePaymentPolicy::payableAmount(self::invoice(['paid_total' => 500.0]), self::enabledConfig()));
        self::assertSame(121000, OnlinePaymentPolicy::toMinorUnits(1210.0));
        self::assertSame(1999, OnlinePaymentPolicy::toMinorUnits(19.99));
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function notPayable(): iterable
    {
        yield 'draft' => [['status' => 'draft']];
        yield 'stornováno' => [['status' => 'cancelled']];
        yield 'zaplaceno' => [['status' => 'paid', 'paid_total' => 1210.0]];
        yield 'dobropis' => [['invoice_type' => 'credit_note']];
        yield 'hotovost' => [['payment_method' => 'cash']];
        yield 'nepodporovaná měna' => [['currency' => 'USD']];
        yield 'pod minimem Stripe' => [['amount_to_pay' => 10.0]];
    }

    /** @param array<string,mixed> $over */
    #[DataProvider('notPayable')]
    public function testNotPayableInvoicesGetNoLink(array $over): void
    {
        self::assertNull(OnlinePaymentPolicy::payableAmount(self::invoice($over), self::enabledConfig()));
    }

    public function testDisabledConfigNeverOffersPayment(): void
    {
        self::assertNull(OnlinePaymentPolicy::payableAmount(self::invoice(), new StripeConfig(false, 'sk_test_abc', 'whsec_abc', ['CZK'])));
    }

    // ── HTTP klient ───────────────────────────────────────────────────

    public function testCreateSessionSendsFormEncodedRequestWithAuthAndIdempotency(): void
    {
        $history = [];
        $client = $this->client([new Response(200, [], '{"id":"cs_test_1","url":"https://checkout.stripe.com/c/pay/cs_test_1"}')], $history);

        $session = $client->createCheckoutSession([
            'mode' => 'payment',
            'line_items' => [['quantity' => 1, 'price_data' => ['currency' => 'czk', 'unit_amount' => 121000]]],
        ], 'idem-1');

        self::assertSame('cs_test_1', $session['id']);
        $req = $history[0]['request'];
        self::assertSame('POST', $req->getMethod());
        self::assertSame('https://api.stripe.test/v1/checkout/sessions', (string) $req->getUri());
        self::assertSame('Bearer sk_test_abc123', $req->getHeaderLine('Authorization'));
        self::assertSame('idem-1', $req->getHeaderLine('Idempotency-Key'));
        self::assertSame('application/x-www-form-urlencoded', $req->getHeaderLine('Content-Type'));
        parse_str((string) $req->getBody(), $form);
        self::assertSame('121000', $form['line_items'][0]['price_data']['unit_amount']);
        self::assertSame('payment', $form['mode']);
    }

    public function testApiErrorBecomesStripeExceptionWithoutKey(): void
    {
        $history = [];
        $client = $this->client([new Response(400, [], '{"error":{"message":"Invalid currency","code":"parameter_invalid"}}')], $history);
        try {
            $client->createCheckoutSession([], 'k');
            self::fail('Očekávána StripeException.');
        } catch (StripeException $e) {
            self::assertStringContainsString('Invalid currency', $e->getMessage());
            self::assertStringNotContainsString('sk_test_abc123', $e->getMessage());
        }
    }

    public function testRetrieveRejectsForeignSessionIdWithoutRequest(): void
    {
        $history = [];
        $client = $this->client([], $history);
        try {
            $client->retrieveCheckoutSession('../../v1/charges');
            self::fail('Očekávána StripeException.');
        } catch (StripeException) {
            self::assertSame([], $history);
        }
    }

    /**
     * @param list<Response> $responses
     * @param array<int,array<string,mixed>> $history
     */
    private function client(array $responses, array &$history): StripeApiClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));
        return new StripeApiClient(
            new Config(['stripe' => ['secret_key' => 'sk_test_abc123', 'api_base' => 'https://api.stripe.test']]),
            new Client(['handler' => $stack]),
        );
    }
}
