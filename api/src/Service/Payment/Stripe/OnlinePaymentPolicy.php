<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payment\Stripe;

use MyInvoice\Service\Invoice\InvoiceCalendar;
use MyInvoice\Service\Invoice\InvoicePaidState;

/**
 * Kdy faktura nabízí „Zaplatit online" (Stripe Checkout). Pure — sdílí ji PDF,
 * e-mail, web faktura i platební endpoint, aby všude platilo totéž.
 */
final class OnlinePaymentPolicy
{
    private const PAYABLE_TYPES = ['invoice', 'proforma'];
    private const PAYABLE_STATUSES = ['issued', 'sent', 'reminded', 'paid'];
    /** Hotovost online platbu nenabízí; převod i karta ano. */
    private const PAYMENT_METHODS = ['bank_transfer', 'card'];
    /** Stripe minimum pro CZK je 15 Kč, pro EUR 0,50 €; menší částku Checkout odmítne. */
    private const MIN_AMOUNT = ['CZK' => 15.0, 'EUR' => 0.5];

    /**
     * Částka k online úhradě (zbývající dluh v měně faktury), nebo null, když se
     * online platba nemá nabízet.
     *
     * @param array<string,mixed> $invoice řádek z InvoiceRepository::find()
     */
    public static function payableAmount(array $invoice, StripeConfig $config): ?float
    {
        if (!$config->isEnabled()) return null;
        if (!in_array((string) ($invoice['invoice_type'] ?? ''), self::PAYABLE_TYPES, true)) return null;
        if (!in_array((string) ($invoice['status'] ?? ''), self::PAYABLE_STATUSES, true)) return null;
        if (InvoiceCalendar::hasSchedule($invoice['invoice_type'] ?? null)) return null;
        if (!in_array((string) ($invoice['payment_method'] ?? 'bank_transfer'), self::PAYMENT_METHODS, true)) return null;
        $currency = strtoupper((string) ($invoice['currency'] ?? ''));
        if (!$config->supportsCurrency($currency)) return null;
        if (InvoicePaidState::isEffectivelyPaid($invoice)) return null;

        $remaining = round((float) ($invoice['amount_to_pay'] ?? 0) - (float) ($invoice['paid_total'] ?? 0), 2);
        if ($remaining < (self::MIN_AMOUNT[$currency] ?? 0.5)) return null;
        return $remaining;
    }

    /** Částka v nejmenších jednotkách měny pro Stripe (CZK i EUR mají 2 desetinná místa). */
    public static function toMinorUnits(float $amount): int
    {
        return (int) round($amount * 100);
    }
}
