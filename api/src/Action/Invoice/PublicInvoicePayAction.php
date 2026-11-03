<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Approval\ApprovalTokenValidator;
use MyInvoice\Service\Invoice\InvoicePublicLinkFeature;
use MyInvoice\Service\Invoice\InvoicePublicLinkService;
use MyInvoice\Service\Payment\Stripe\StripeException;
use MyInvoice\Service\Payment\Stripe\StripeOnlinePaymentService;
use MyInvoice\Service\Tenant\PublicTenantGuard;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * GET /api/public/invoice/{token}/pay — „Zaplatit online" (Stripe Checkout).
 *
 * Trvalý odkaz z PDF, QR kódu, e-mailu a web faktury. Teprve tady vzniká
 * (nebo se znovu použije) Checkout Session a zákazník se přesměruje na Stripe.
 * Když už není co platit (zaplaceno, vypnuto), vrátí ho na web fakturu —
 * starý odkaz v PDF tak nikdy nekončí chybou ani druhou platbou.
 */
final class PublicInvoicePayAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly InvoicePublicLinkFeature $feature,
        private readonly InvoicePublicLinkService $links,
        private readonly PublicTenantGuard $tenantGuard,
        private readonly StripeOnlinePaymentService $stripe,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $token = (string) ($args['token'] ?? '');
        if (!$this->feature->isEnabled() || !ApprovalTokenValidator::isValidFormat($token)) {
            return Json::error($response, 'not_found', 'Tento odkaz není platný.', 404);
        }
        $invoice = $this->repo->findByPublicToken($token); // drafty filtruje SQL
        if ($invoice === null || !$this->tenantGuard->allows($request, (int) $invoice['supplier_id'])) {
            return Json::error($response, 'not_found', 'Tento odkaz není platný.', 404);
        }
        $back = $this->links->url($token, (int) $invoice['supplier_id']);

        try {
            $checkout = $this->stripe->checkoutUrlForToken($token);
        } catch (StripeException $e) {
            $this->logger->error('stripe.checkout_failed', ['invoice_id' => (int) $invoice['id'], 'error' => $e->getMessage()]);
            return $this->redirect($response, $back . '?payment=unavailable');
        }
        if ($checkout === null) {
            return $this->redirect($response, $back);
        }
        return $this->redirect($response, $checkout);
    }

    private function redirect(Response $response, string $url): Response
    {
        return $response->withStatus(303)
            ->withHeader('Location', $url)
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Referrer-Policy', 'no-referrer');
    }
}
