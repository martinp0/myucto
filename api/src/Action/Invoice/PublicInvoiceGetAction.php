<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\DemoReadOnlyMiddleware;
use MyInvoice\Repository\InvoiceAttachmentRepository;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Approval\ApprovalTokenValidator;
use MyInvoice\Service\Invoice\InvoicePublicLinkFeature;
use MyInvoice\Service\Invoice\InvoiceIsdocDeliverySettings;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Mail\InvoiceEmailVarsBuilder;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Service\Tenant\PublicTenantGuard;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/public/invoice/{token}
 *
 * Veřejná „web faktura" (bez auth) — data pro HTML náhled vystavené faktury.
 * Token je v invoices.public_token (trvalý, revokace = regenerace). Draft se
 * nezobrazuje (404 stejně jako neexistující token — neprozrazovat existenci).
 *
 * Vrací STRIKTNĚ whitelistovaný set polí (viz buildPayload) — dodavatel,
 * odběratel, položky, součty, DPH rekapitulace, platební údaje + QR. Data se
 * resolvují snapshot-first stejně jako PDF (InvoicePdfRenderer::resolve*),
 * takže náhled ukazuje totéž co PDF.
 *
 * Zobrazení anonymním návštěvníkem nastaví public_viewed_at (indikace
 * „zobrazeno klientem"); přihlášený interní uživatel indikaci neovlivní
 * (vzor PublicWorkReportGetAction).
 */
final class PublicInvoiceGetAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly InvoiceAttachmentRepository $attachments,
        private readonly InvoicePdfRenderer $renderer,
        private readonly InvoiceEmailVarsBuilder $emailVars,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly PublicTenantGuard $tenantGuard,
        private readonly InvoicePublicLinkFeature $feature,
        private readonly InvoiceIsdocDeliverySettings $isdocSettings,
        private readonly \MyInvoice\Service\Invoice\InvoicePublicLinkService $publicLinks,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        // Vypnutá web faktura = odkaz neexistuje (stejná 404 jako neplatný token).
        if (!$this->feature->isEnabled()) {
            return Json::error($response, 'not_found', 'Tento odkaz není platný.', 404);
        }

        $token = (string) ($args['token'] ?? '');
        if (!ApprovalTokenValidator::isValidFormat($token)) {
            return Json::error($response, 'invalid_token', 'Neplatný odkaz.', 404);
        }

        $invoice = $this->repo->findByPublicToken($token); // drafty filtruje SQL
        if ($invoice === null) {
            return Json::error($response, 'token_invalid_or_expired',
                'Tento odkaz není platný nebo byl zneplatněn.', 404);
        }
        if (!$this->tenantGuard->allows($request, (int) $invoice['supplier_id'])) {
            return Json::error($response, 'not_found', 'Tento odkaz není platný.', 404);
        }

        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if ((!is_array($user) || empty($user['id'])) && !DemoReadOnlyMiddleware::enabled($request)) {
            $firstView = empty($invoice['public_viewed_at']);
            $this->repo->markPublicViewed((int) $invoice['id']);
            if ($firstView) {
                $this->logger->log('invoice.public_viewed', null, 'invoice', (int) $invoice['id'], [],
                    $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
                    $request->getHeaderLine('User-Agent'), (int) $invoice['supplier_id']);
            }
        }

        return Json::ok($response, self::buildPayload(
            $invoice,
            $this->renderer->resolveSupplier($invoice),
            $this->renderer->resolveClient($invoice),
            $this->renderer->resolveBank($invoice),
            $this->emailVars->paymentQrDataUri($invoice),
            $this->attachments->listForInvoice((int) $invoice['id']),
            $this->renderer->ossClause($invoice),
            $this->isdocSettings->publicEnabled($invoice),
            $this->onlinePaymentUrl($invoice),
        ));
    }

    /** „Zaplatit online" (Stripe Checkout) — null, když je vypnuté nebo není co platit. */
    private function onlinePaymentUrl(array $invoice): ?string
    {
        try {
            return $this->publicLinks->ensurePayUrl($invoice);
        } catch (\Throwable) {
            return null; // web faktura se zobrazí i bez platebního tlačítka
        }
    }

    /**
     * Whitelist public polí — jediné místo, které rozhoduje, co veřejný endpoint
     * prozradí. Pure function (public static kvůli testovatelnosti bez DB,
     * vzor ApprovalEmailVarsBuilder::buildSubject). Nikdy sem nepřidávat tokeny,
     * snapshoty, interní ID vazeb ani e-maily klienta.
     *
     * @param array<string,mixed>      $invoice     Řádek z InvoiceRepository::find()
     * @param array<string,mixed>      $supplier    Z InvoicePdfRenderer::resolveSupplier()
     * @param array<string,mixed>      $client      Z InvoicePdfRenderer::resolveClient()
     * @param array<string,mixed>|null $bank        Z InvoicePdfRenderer::resolveBank()
     * @param list<array<string,mixed>> $attachments Z InvoiceAttachmentRepository::listForInvoice()
     * @return array<string,mixed>
     */
    public static function buildPayload(
        array $invoice,
        array $supplier,
        array $client,
        ?array $bank,
        ?string $qrDataUri,
        array $attachments = [],
        ?array $ossClause = null,
        bool $isdocAvailable = false,
        ?string $onlinePaymentUrl = null,
    ): array {
        $pick = static fn (array $src, array $keys): array =>
            array_intersect_key($src, array_flip($keys));

        $publicInvoice = $pick($invoice, [
            'varsymbol', 'payment_varsymbol', 'invoice_type', 'status', 'payment_status', 'language',
            'currency', 'currency_decimals',
            'issue_date', 'tax_date', 'due_date', 'paid_at',
            'payment_method', 'reverse_charge', 'prices_include_vat',
            'note_above_items', 'note_below_items',
            'amount_to_pay', 'paid_total',
            'totals', 'vat_breakdown', 'czk_recap',
        ]);
        // „Zaplaceno" stejně jako PDF a e-mail; holý status 'paid' by u faktury
        // s dodatečně přidanými položkami zatajil doplatek.
        $publicInvoice['is_paid'] = \MyInvoice\Service\Invoice\InvoicePaidState::isEffectivelyPaid($invoice);
        $publicInvoice['items'] = array_map(
            static fn (array $it): array => $pick($it, [
                'description', 'quantity', 'duration_minutes', 'unit', 'unit_price_without_vat',
                'vat_rate_snapshot', 'total_without_vat', 'total_with_vat', 'item_kind',
            ]),
            (array) ($invoice['items'] ?? []),
        );

        return [
            'invoice'  => $publicInvoice,
            'isdoc_available' => $isdocAvailable,
            // Podmnožina polí, která tiskne PDF šablona (api/templates/invoice/invoice.twig)
            'supplier' => $pick($supplier, [
                'company_name', 'street', 'city', 'zip',
                'country_name_cs', 'country_name_en', 'ic', 'dic',
                'is_vat_payer', 'commercial_register', 'email', 'web',
            ]),
            // Koncový zákazník zjednodušeného dokladu (§ 30) se nezobrazuje, stejně jako v PDF.
            // Totéž platí pro zjednodušený doklad s volbou „netisknout odběratele".
            'client'   => !empty($client['is_walk_in'])
                    || \MyInvoice\Service\Invoice\SimplifiedDocumentPolicy::hidesCustomer($invoice, $supplier)
                ? null : $pick($client, [
                'company_name', 'first_name', 'last_name', 'street', 'city', 'zip',
                'country_name_cs', 'country_name_en', 'country_iso2',
                'ic', 'dic', 'tax_number',
            ]),
            'bank'     => $bank === null ? null : $pick($bank, [
                'account_number', 'bank_code', 'bank_name', 'iban', 'bic',
            ]),
            'qr_data_uri' => $qrDataUri,
            // Trvalý odkaz /api/public/invoice/{token}/pay (Stripe Checkout); null =
            // online platba vypnutá, faktura zaplacená nebo nepodporovaná měna.
            'online_payment_url' => $onlinePaymentUrl,
            // OSS doložka — náhled musí říct totéž co PDF (InvoicePdfRenderer::ossClause).
            // Jen odvozená metadata (státy spotřeby, celý/smíšený doklad), žádné částky
            // ani per-řádkové OSS sloupce navíc.
            'oss_clause'  => $ossClause,
            // Přílohy e-mailu ke stažení — id slouží jen jako klíč pro download
            // URL (gated tokenem faktury); NIKDY filename (název na disku),
            // sha256 ani uploaded_by.
            'attachments' => array_map(
                static fn (array $a): array => $pick($a, [
                    'id', 'original_name', 'size_bytes',
                ]),
                $attachments,
            ),
        ];
    }
}
