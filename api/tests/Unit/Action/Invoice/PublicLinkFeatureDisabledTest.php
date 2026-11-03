<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Invoice;

use MyInvoice\Action\Invoice\PublicInvoiceAttachmentAction;
use MyInvoice\Action\Invoice\PublicInvoiceGetAction;
use MyInvoice\Action\Invoice\PublicInvoicePdfAction;
use MyInvoice\Action\Invoice\PublicLinkAction;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\InvoiceAttachmentRepository;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\InvoicePublicLinkFeature;
use MyInvoice\Service\Invoice\InvoiceIsdocDeliverySettings;
use MyInvoice\Service\Invoice\InvoicePublicLinkService;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Mail\InvoiceEmailVarsBuilder;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Service\Tenant\PublicTenantGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Vypnutá web faktura nesmí jít obejít přímým voláním API: správa odkazu token
 * nezaloží ani nepřegeneruje a veřejné stránky dřív rozeslaných odkazů vrací
 * 404 dřív, než se sáhne do databáze.
 */
final class PublicLinkFeatureDisabledTest extends TestCase
{
    private const TOKEN = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718';

    /** @return array<string,array{string}> */
    public static function managementProvider(): array
    {
        return [
            'vytvoření odkazu' => ['ensure'],
            'nový odkaz'       => ['regenerate'],
        ];
    }

    #[DataProvider('managementProvider')]
    public function testSpravaOdkazuJeVypnutaBezSahnutiNaFakturu(string $handler): void
    {
        $repo = $this->createMock(InvoiceRepository::class);
        $repo->expects(self::never())->method(self::anything());

        $response = $this->linkAction($repo, false)->{$handler}(
            $this->userRequest(),
            (new ResponseFactory())->createResponse(),
            ['id' => 5],
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('public_links_disabled', (string) $response->getBody());
    }

    public function testZapnutaSpravaOdkazuVraciOdkaz(): void
    {
        $repo = $this->createStub(InvoiceRepository::class);
        $repo->method('find')->willReturn([
            'id' => 5, 'supplier_id' => 41, 'status' => 'sent',
            'public_token' => self::TOKEN, 'public_viewed_at' => null,
        ]);

        $response = $this->linkAction($repo, true)->ensure(
            $this->userRequest(),
            (new ResponseFactory())->createResponse(),
            ['id' => 5],
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('https://app.example.test/invoice/' . self::TOKEN, (string) $response->getBody());
    }

    /** @return array<string,array{string}> */
    public static function publicProvider(): array
    {
        return [
            'náhled'  => ['get'],
            'PDF'     => ['pdf'],
            'příloha' => ['attachment'],
        ];
    }

    #[DataProvider('publicProvider')]
    public function testVerejnaStrankaJeVypnutaBezSahnutiNaFakturu(string $endpoint): void
    {
        $repo = $this->createMock(InvoiceRepository::class);
        $repo->expects(self::never())->method(self::anything());

        $response = $this->callPublic($endpoint, $repo, false);

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('not_found', (string) $response->getBody());
    }

    /**
     * Protikus: se zapnutou web fakturou požadavek projde až k vyhledání tokenu
     * (neznámý token = token_invalid_or_expired), brána tedy není zavřená napořád.
     */
    #[DataProvider('publicProvider')]
    public function testZapnutaVerejnaStrankaHledaToken(string $endpoint): void
    {
        $repo = $this->createStub(InvoiceRepository::class);
        $repo->method('findByPublicToken')->willReturn(null);
        $repo->method('publicInvoiceRefByToken')->willReturn(null);

        $response = $this->callPublic($endpoint, $repo, true);

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('token_invalid_or_expired', (string) $response->getBody());
    }

    private function callPublic(string $endpoint, InvoiceRepository $repo, bool $enabled): ResponseInterface
    {
        $feature = $this->feature($enabled);
        $logger = $this->createStub(ActivityLogger::class);
        $ip = $this->createStub(IpMatcher::class);
        $guard = $this->createStub(PublicTenantGuard::class);
        $guard->method('allows')->willReturn(true);
        $renderer = $this->createStub(InvoicePdfRenderer::class);
        $attachments = (new \ReflectionClass(InvoiceAttachmentRepository::class))->newInstanceWithoutConstructor();
        $emailVars = (new \ReflectionClass(InvoiceEmailVarsBuilder::class))->newInstanceWithoutConstructor();
        $isdocSettings = new InvoiceIsdocDeliverySettings($this->createStub(Connection::class));

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/public/invoice/' . self::TOKEN);
        $response = (new ResponseFactory())->createResponse();
        $args = ['token' => self::TOKEN, 'attId' => 3];

        return match ($endpoint) {
            'get' => (new PublicInvoiceGetAction($repo, $attachments, $renderer, $emailVars, $logger, $ip, $guard, $feature, $isdocSettings,
                new \MyInvoice\Service\Invoice\InvoicePublicLinkService(new \MyInvoice\Infrastructure\Config\Config([]), $repo)))
                ($request, $response, $args),
            'pdf' => (new PublicInvoicePdfAction($renderer, $repo, $logger, $ip, $guard, $feature))
                ($request, $response, $args),
            'attachment' => (new PublicInvoiceAttachmentAction($repo, $attachments, $logger, $ip, $guard, $feature))
                ($request, $response, $args),
        };
    }

    private function linkAction(InvoiceRepository $repo, bool $enabled): PublicLinkAction
    {
        return new PublicLinkAction(
            $repo,
            new InvoicePublicLinkService(new Config(['app' => ['url' => 'https://app.example.test']]), $repo),
            $this->createStub(ActivityLogger::class),
            $this->createStub(IpMatcher::class),
            $this->feature($enabled),
        );
    }

    private function feature(bool $enabled): InvoicePublicLinkFeature
    {
        return new InvoicePublicLinkFeature(new Config(['invoices' => ['public_links' => $enabled]]));
    }

    private function userRequest(): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/invoices/5/public-link')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 17, 'role' => 'admin', 'is_superadmin' => true])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 41);
    }
}
