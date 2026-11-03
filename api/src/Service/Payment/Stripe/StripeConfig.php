<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payment\Stripe;

use MyInvoice\Infrastructure\Config\Config;

/**
 * Konfigurace volitelné online platby faktur přes Stripe Checkout.
 *
 * Výchozí stav je VYPNUTO. Funkce je aktivní, jen když je zároveň:
 *   - `stripe.enabled = true`      (ENV MYINVOICE_STRIPE_ENABLED)
 *   - `stripe.secret_key`          (ENV STRIPE_SECRET_KEY, `sk_test_…` / `sk_live_…`
 *                                   nebo omezený `rk_…`)
 *   - `stripe.webhook_secret`      (ENV STRIPE_WEBHOOK_SECRET, `whsec_…`)
 *
 * Bez webhook secretu se funkce nezapne záměrně: zákazník by zaplatil, ale
 * faktura by se nikdy neoznačila jako uhrazená.
 */
final class StripeConfig
{
    public const DEFAULT_API_BASE = 'https://api.stripe.com';

    /** @param list<string> $currencies */
    public function __construct(
        public readonly bool $enabled,
        public readonly string $secretKey,
        public readonly string $webhookSecret,
        public readonly array $currencies,
        public readonly string $apiBase = self::DEFAULT_API_BASE,
        public readonly int $webhookToleranceSec = 300,
    ) {}

    public static function fromConfig(Config $config): self
    {
        $currencies = $config->get('stripe.currencies', ['CZK']);
        if (is_string($currencies)) {
            $currencies = explode(',', $currencies);
        }
        $currencies = array_values(array_filter(array_map(
            static fn ($c): string => strtoupper(trim((string) $c)),
            (array) $currencies,
        ), static fn (string $c): bool => preg_match('/^[A-Z]{3}$/', $c) === 1));

        return new self(
            (bool) $config->get('stripe.enabled', false),
            trim((string) $config->get('stripe.secret_key', '')),
            trim((string) $config->get('stripe.webhook_secret', '')),
            $currencies === [] ? ['CZK'] : $currencies,
            rtrim((string) ($config->get('stripe.api_base', self::DEFAULT_API_BASE) ?: self::DEFAULT_API_BASE), '/'),
            max(30, (int) $config->get('stripe.webhook_tolerance_sec', 300)),
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled
            && preg_match('/^(sk|rk)_(test|live)_[A-Za-z0-9]+$/', $this->secretKey) === 1
            && str_starts_with($this->webhookSecret, 'whsec_');
    }

    public function isTestMode(): bool
    {
        return str_contains($this->secretKey, '_test_');
    }

    public function supportsCurrency(string $currency): bool
    {
        return in_array(strtoupper($currency), $this->currencies, true);
    }
}
