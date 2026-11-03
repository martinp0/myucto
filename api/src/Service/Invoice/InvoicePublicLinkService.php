<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Tenant\TenantUrlResolver;

/**
 * Jediný vlastník URL formátu web faktury `/invoice/{token}` (vzor
 * WorkReportLinkService::publicUrl) — používá ho management endpoint
 * (PublicLinkAction) i e-mail (InvoiceEmailVarsBuilder). Cesta musí ladit
 * s Vue routou ve web/src/router/index.ts.
 */
final class InvoicePublicLinkService
{
    public function __construct(
        private readonly Config $config,
        private readonly InvoiceRepository $invoices,
        private readonly ?TenantUrlResolver $tenantUrls = null,
    ) {}

    /**
     * URL pro daný token. Bez nakonfigurovaného app.url vrací relativní cestu —
     * UI („kopírovat odkaz") tak vždy něco dostane; e-mailová cesta absolutnost
     * hlídá v ensureUrl().
     */
    public function url(string $token, int $supplierId = 0): string
    {
        if ($this->tenantUrls !== null && $supplierId > 0) {
            return $this->tenantUrls->forSupplier($supplierId, 'public_links', '/invoice/' . $token);
        }
        return rtrim((string) $this->config->get('app.url', ''), '/') . '/invoice/' . $token;
    }

    /**
     * Absolutní URL web faktury pro e-mail; token vytvoří lazy při prvním
     * použití. Null pro draft (veřejná stránka koncepty nezobrazuje), bez
     * app.url (relativní odkaz je v e-mailu k ničemu) a při vypnuté web faktuře
     * (InvoicePublicLinkFeature) — tehdy ani nevzniká token.
     */
    public function ensureUrl(array $invoice): ?string
    {
        if (!(new InvoicePublicLinkFeature($this->config))->isEnabled()) {
            return null;
        }
        if (($invoice['status'] ?? '') === 'draft') {
            return null;
        }
        if (rtrim((string) $this->config->get('app.url', ''), '/') === '') {
            return null;
        }
        $token = (string) ($invoice['public_token'] ?? '');
        if ($token === '') {
            $token = $this->invoices->ensurePublicToken((int) $invoice['id']);
        }
        return $this->url($token, (int) ($invoice['supplier_id'] ?? 0));
    }

    /**
     * Trvalý odkaz „Zaplatit online" (Stripe Checkout) pro PDF, e-mail a web
     * fakturu, nebo null, když se online platba nemá nabízet
     * ({@see \MyInvoice\Service\Payment\Stripe\OnlinePaymentPolicy}).
     *
     * Nevede přímo na Stripe — Checkout Session platí nejvýš 24 h. Endpoint
     * `/api/public/invoice/{token}/pay` session založí až při kliknutí. Proto je
     * podmínkou zapnutá web faktura (token) a absolutní app.url.
     */
    public function ensurePayUrl(array $invoice): ?string
    {
        $stripe = \MyInvoice\Service\Payment\Stripe\StripeConfig::fromConfig($this->config);
        if (\MyInvoice\Service\Payment\Stripe\OnlinePaymentPolicy::payableAmount($invoice, $stripe) === null) {
            return null;
        }
        $publicUrl = $this->ensureUrl($invoice);
        if ($publicUrl === null) {
            return null;
        }
        $token = (string) ($invoice['public_token'] ?? '');
        if ($token === '') {
            $token = $this->invoices->ensurePublicToken((int) $invoice['id']); // idempotentní
        }
        return $this->payUrl($token, (int) ($invoice['supplier_id'] ?? 0));
    }

    /** URL platebního endpointu pro token web faktury. */
    public function payUrl(string $token, int $supplierId = 0): string
    {
        $path = '/api/public/invoice/' . $token . '/pay';
        if ($this->tenantUrls !== null && $supplierId > 0) {
            return $this->tenantUrls->forSupplier($supplierId, 'public_links', $path);
        }
        return rtrim((string) $this->config->get('app.url', ''), '/') . $path;
    }
}
