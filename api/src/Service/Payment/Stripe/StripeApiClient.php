<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payment\Stripe;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use MyInvoice\Infrastructure\Config\Config;

/**
 * Tenký klient Stripe REST API (jen to, co online platba faktury potřebuje).
 * Bez stripe-php SDK: dvě volání form-encoded přes Guzzle, který aplikace už má.
 *
 * `$http` je volitelný jen kvůli testům (Guzzle MockHandler). PHP-DI volitelný
 * parametr nevyplní, takže v produkci si klient postaví vlastní Guzzle.
 */
class StripeApiClient
{
    private readonly ClientInterface $http;

    public function __construct(
        private readonly Config $config,
        ?ClientInterface $http = null,
    ) {
        $this->http = $http ?? new Client(['timeout' => 15, 'connect_timeout' => 5, 'http_errors' => false]);
    }

    /**
     * POST /v1/checkout/sessions
     *
     * @param array<string,mixed> $params Stripe parametry (vnořené pole → form encoding)
     * @return array<string,mixed> Checkout Session
     */
    public function createCheckoutSession(array $params, string $idempotencyKey): array
    {
        return $this->request('POST', '/v1/checkout/sessions', $params, $idempotencyKey);
    }

    /** @return array<string,mixed> */
    public function retrieveCheckoutSession(string $sessionId): array
    {
        if (preg_match('/^cs_[A-Za-z0-9_]+$/', $sessionId) !== 1) {
            throw new StripeException('Neplatné id Checkout Session.');
        }
        return $this->request('GET', '/v1/checkout/sessions/' . $sessionId, [], null);
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function request(string $method, string $path, array $params, ?string $idempotencyKey): array
    {
        $cfg = StripeConfig::fromConfig($this->config);
        if ($cfg->secretKey === '') {
            throw new StripeException('Stripe secret key není nastaven.');
        }
        $headers = [
            'Authorization'  => 'Bearer ' . $cfg->secretKey,
            'Stripe-Version' => '2025-03-31.basil',
        ];
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }
        $options = ['headers' => $headers, 'http_errors' => false];
        if ($method === 'POST') {
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
            $options['headers'] = $headers;
            $options['body'] = http_build_query($params, '', '&', PHP_QUERY_RFC1738);
        }
        try {
            $res = $this->http->request($method, $cfg->apiBase . $path, $options);
        } catch (GuzzleException $e) {
            throw new StripeException('Stripe API je nedostupné: ' . $e->getMessage(), 0, $e);
        }
        $body = (string) $res->getBody();
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new StripeException('Stripe API vrátilo nečitelnou odpověď (HTTP ' . $res->getStatusCode() . ').');
        }
        if ($res->getStatusCode() >= 400) {
            // Zpráva Stripe nikdy neobsahuje klíč; type/code pro diagnostiku.
            $err = (array) ($data['error'] ?? []);
            throw new StripeException(sprintf(
                'Stripe API chyba %d: %s (%s)',
                $res->getStatusCode(),
                (string) ($err['message'] ?? 'neznámá chyba'),
                (string) ($err['code'] ?? $err['type'] ?? '-'),
            ));
        }
        return $data;
    }
}
