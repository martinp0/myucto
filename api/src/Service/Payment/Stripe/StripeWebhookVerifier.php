<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payment\Stripe;

/**
 * Ověření podpisu Stripe webhooku (`Stripe-Signature: t=…,v1=…`) bez SDK.
 *
 * Postup podle dokumentace Stripe: HMAC-SHA256 z `"{t}.{raw body}"` klíčem
 * `whsec_…`, porovnání v konstantním čase se kterýmkoli `v1`, a tolerance
 * časového razítka proti přehrání starého požadavku.
 */
final class StripeWebhookVerifier
{
    /**
     * @return array<string,mixed> dekódovaná událost
     * @throws StripeException neplatný podpis, staré razítko nebo nečitelné tělo
     */
    public static function verify(
        string $payload,
        string $signatureHeader,
        string $secret,
        int $toleranceSec = 300,
        ?int $now = null,
    ): array {
        if ($secret === '') {
            throw new StripeException('Webhook secret není nastaven.');
        }
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $signatureHeader) as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) !== 2) {
                continue;
            }
            if ($kv[0] === 't' && ctype_digit($kv[1])) {
                $timestamp = (int) $kv[1];
            } elseif ($kv[0] === 'v1') {
                $signatures[] = $kv[1];
            }
        }
        if ($timestamp === null || $signatures === []) {
            throw new StripeException('Chybí podpis Stripe-Signature.');
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        $ok = false;
        foreach ($signatures as $sig) {
            if (hash_equals($expected, $sig)) {
                $ok = true;
                break;
            }
        }
        if (!$ok) {
            throw new StripeException('Neplatný podpis webhooku.');
        }
        $now ??= time();
        if (abs($now - $timestamp) > $toleranceSec) {
            throw new StripeException('Časové razítko webhooku je mimo toleranci.');
        }
        try {
            $event = json_decode($payload, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new StripeException('Tělo webhooku není platný JSON.');
        }
        if (!is_array($event) || !isset($event['type'], $event['data']['object'])) {
            throw new StripeException('Tělo webhooku není Stripe událost.');
        }
        return $event;
    }

    /** Hlavička pro testy a lokální simulaci (stejný algoritmus jako Stripe). */
    public static function sign(string $payload, string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();
        return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    }
}
