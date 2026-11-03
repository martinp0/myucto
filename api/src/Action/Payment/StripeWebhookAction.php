<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payment;

use MyInvoice\Http\Json;
use MyInvoice\Service\Payment\Stripe\StripeException;
use MyInvoice\Service\Payment\Stripe\StripeOnlinePaymentService;
use MyInvoice\Service\Payment\Stripe\StripeWebhookVerifier;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * POST /api/public/stripe/webhook — události Stripe Checkout.
 *
 * Bez session/CSRF (volá Stripe); autenticita = podpis `Stripe-Signature`
 * ověřený klíčem STRIPE_WEBHOOK_SECRET. Vypnutá funkce = 404 (endpoint
 * navenek neexistuje). Chyba zpracování = 500, aby Stripe doručení zopakoval.
 */
final class StripeWebhookAction
{
    public function __construct(
        private readonly StripeOnlinePaymentService $stripe,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $cfg = $this->stripe->config();
        if (!$cfg->isEnabled()) {
            return Json::error($response, 'not_found', 'Not found.', 404);
        }
        $body = $request->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $payload = (string) $body->getContents();
        try {
            $event = StripeWebhookVerifier::verify(
                $payload,
                $request->getHeaderLine('Stripe-Signature'),
                $cfg->webhookSecret,
                $cfg->webhookToleranceSec,
            );
        } catch (StripeException $e) {
            $this->logger->warning('stripe.webhook_rejected', ['error' => $e->getMessage()]);
            return Json::error($response, 'invalid_signature', 'Neplatný podpis webhooku.', 400);
        }

        try {
            $result = $this->stripe->handleEvent($event);
        } catch (\Throwable $e) {
            $this->logger->error('stripe.webhook_failed', [
                'event' => (string) ($event['id'] ?? ''),
                'type'  => (string) ($event['type'] ?? ''),
                'error' => $e->getMessage(),
            ]);
            return Json::error($response, 'processing_failed', 'Událost se nepodařilo zpracovat.', 500);
        }
        return Json::ok($response, ['received' => true, 'result' => $result]);
    }
}
