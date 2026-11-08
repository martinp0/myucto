<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Settings;

use MyInvoice\Action\Settings\BankAccountChangeProposalAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\BankStatementOwnershipResolver;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\MfaStepUpService;
use MyInvoice\Service\Auth\SecurityClock;
use MyInvoice\Service\Bank\BankAccountChangeProposalMailer;
use MyInvoice\Service\Bank\BankAccountChangeProposalService;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Mail\Mailer;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use PDO;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Token smí bankovní spojení jen navrhnout. Schválení je session správce
 * s čerstvým step-up. Do té doby se měna, snímek faktury ani QR zdroj nemění.
 */
#[Group('integration')]
#[AllowMockObjectsWithoutExpectations]
final class BankAccountChangeProposalTest extends TestCase
{
    private const OLD_ACCOUNT = '1000000019';
    private const NEW_ACCOUNT = '1000000005';
    private const NEW_BANK = '0800';
    private const SESSION = 'proposal-step-up-session-token';

    private Connection $db;
    private BankAccountChangeProposalAction $action;
    private MfaStepUpService $stepUp;
    private BankAccountChangeProposalMailer $mail;
    private int $supplierA = 0;
    private int $supplierB = 0;
    private int $adminId = 0;
    private int $accountantId = 0;
    private int $outsiderId = 0;
    private int $tokenId = 0;
    private int $plainTokenId = 0;
    private int $invoiceId = 0;
    private int $invoiceCurrencyId = 0;
    private string $adminEmail = '';
    private string $accountantEmail = '';
    private string $outsiderEmail = '';

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            self::markTestSkipped('Test vyžaduje cfg.php.');
        }
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->db->pdo()->exec("SET SESSION sql_mode = CONCAT(@@SESSION.sql_mode, ',STRICT_ALL_TABLES')");
        $this->stepUp = $container->get(MfaStepUpService::class);
        $this->mail = new class($this->createStub(Mailer::class)) extends BankAccountChangeProposalMailer {
            /** @var list<array{locale:string,email:string,vars:array<string,mixed>,supplierId:int}> */
            public array $sent = [];

            protected function deliver(string $locale, string $email, array $vars, int $supplierId): void
            {
                $this->sent[] = compact('locale', 'email', 'vars', 'supplierId');
            }
        };
        $service = new BankAccountChangeProposalService(
            $this->db,
            $container->get(ActivityLogger::class),
            $container->get(InvoicePdfRenderer::class),
            $container->get(BankStatementOwnershipResolver::class),
            $this->mail,
            $this->stepUp,
            $container->get(SecurityClock::class),
        );
        $this->action = new BankAccountChangeProposalAction($service, new IpMatcher());
        $this->seed();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ([$this->supplierA, $this->supplierB] as $supplierId) {
            if ($supplierId > 0) {
                $pdo->prepare('DELETE FROM invoices WHERE supplier_id = ?')->execute([$supplierId]);
                $pdo->prepare('DELETE FROM clients WHERE supplier_id = ?')->execute([$supplierId]);
                $pdo->prepare('DELETE FROM supplier_bank_accounts WHERE supplier_id = ?')->execute([$supplierId]);
                $pdo->prepare('DELETE FROM currencies WHERE supplier_id = ?')->execute([$supplierId]);
                $pdo->prepare('DELETE FROM user_suppliers WHERE supplier_id = ?')->execute([$supplierId]);
                $pdo->prepare('DELETE FROM bank_account_change_proposals WHERE supplier_id = ?')->execute([$supplierId]);
                $pdo->prepare('DELETE FROM activity_log WHERE supplier_id = ?')->execute([$supplierId]);
                $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$supplierId]);
            }
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        foreach ([$this->adminId, $this->accountantId, $this->outsiderId] as $userId) {
            if ($userId > 0) {
                $pdo->prepare('DELETE FROM api_tokens WHERE user_id = ?')->execute([$userId]);
                $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
            }
        }
        $this->db->close();
    }

    public function testTokenProposalDoesNotChangeInvoiceOrPaymentSourceUntilStepUpApproval(): void
    {
        $currencyId = $this->invoiceCurrencyId;
        $before = $this->bankRow($currencyId);
        $snapshot = $this->invoiceSnapshot();
        $response = $this->propose($currencyId, $this->tokenId, true);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->decode($response);
        self::assertSame($before, $this->bankRow($currencyId));
        self::assertSame($snapshot, $this->invoiceSnapshot());
        self::assertSame(self::NEW_ACCOUNT, $body['proposed']['account_number']);
        self::assertSame(self::OLD_ACCOUNT, $body['baseline']['account_number']);
        self::assertSame('mi_pat_prop', $body['origin']['token_prefix']);
        $this->assertNotified($this->adminEmail, self::NEW_ACCOUNT);
        $this->assertNotNotified($this->accountantEmail);
        $this->assertNotNotified($this->outsiderEmail);
        $this->assertAuditRedacted((int) $body['id'], self::NEW_ACCOUNT, self::NEW_BANK);

        $wrongProof = $this->stepUp->issue(
            $this->adminId,
            self::SESSION,
            MfaStepUpService::bankAccountProposalOperation('approve', (int) $body['id'] + 1000),
            'totp',
        );
        $wrong = $this->action->approve(
            $this->admin($this->supplierA, ['step_up_token' => $wrongProof]),
            new Response(),
            ['id' => (int) $body['id']],
        );
        self::assertSame(403, $wrong->getStatusCode(), (string) $wrong->getBody());
        self::assertSame($before, $this->bankRow($currencyId), 'Cizí step-up proof účet nezapíše.');

        $denied = $this->action->approve(
            $this->bearer($this->supplierA, $this->tokenId, true),
            new Response(),
            ['id' => (int) $body['id']],
        );
        self::assertSame(403, $denied->getStatusCode(), (string) $denied->getBody());
        self::assertSame($before, $this->bankRow($currencyId));

        $proof = $this->stepUp->issue(
            $this->adminId,
            self::SESSION,
            MfaStepUpService::bankAccountProposalOperation('approve', (int) $body['id']),
            'totp',
        );
        $approved = $this->action->approve(
            $this->admin($this->supplierA, ['step_up_token' => $proof]),
            new Response(),
            ['id' => (int) $body['id']],
        );
        self::assertSame(200, $approved->getStatusCode(), (string) $approved->getBody());
        $after = $this->bankRow($currencyId);
        self::assertSame(self::NEW_ACCOUNT, $after['account_number']);
        self::assertSame(self::NEW_BANK, $after['bank_code']);
        self::assertSame($snapshot, $this->invoiceSnapshot(), 'Vystavená faktura se snímkem účtu se schválením nepřepisuje.');

        $again = $this->action->approve(
            $this->admin($this->supplierA, ['step_up_token' => $proof]),
            new Response(),
            ['id' => (int) $body['id']],
        );
        self::assertSame(409, $again->getStatusCode(), 'Vyřízený návrh už podruhé účet nezmění.');
        self::assertSame('already_decided', $this->decode($again)['error']['code'] ?? null);
        self::assertSame($after, $this->bankRow($currencyId));
    }

    public function testRejectAndWithdrawLeavePaymentDataUntouched(): void
    {
        $currencyId = $this->currency($this->supplierA);
        $before = $this->bankRow($currencyId);
        $created = $this->decode($this->propose($currencyId, $this->tokenId, true));
        $proof = $this->stepUp->issue(
            $this->adminId,
            self::SESSION,
            MfaStepUpService::bankAccountProposalOperation('reject', (int) $created['id']),
            'totp',
        );
        $mails = count($this->mail->sent);
        $rejected = $this->action->reject(
            $this->admin($this->supplierA, ['step_up_token' => $proof, 'reason' => 'nesedí']),
            new Response(),
            ['id' => (int) $created['id']],
        );
        self::assertSame(200, $rejected->getStatusCode(), (string) $rejected->getBody());
        self::assertSame('rejected', $this->decode($rejected)['status']);
        self::assertSame($before, $this->bankRow($currencyId));
        self::assertGreaterThan($mails, count($this->mail->sent));

        $second = $this->decode($this->propose($currencyId, $this->tokenId, true, ['account_number' => '1000000027', 'bank_code' => '0100']));
        $withdrawn = $this->action->withdraw(
            $this->bearer($this->supplierA, $this->tokenId, true),
            new Response(),
            ['id' => (int) $second['id']],
        );
        self::assertSame(200, $withdrawn->getStatusCode(), (string) $withdrawn->getBody());
        self::assertSame('withdrawn', $this->decode($withdrawn)['status']);
        self::assertSame($before, $this->bankRow($currencyId));
    }

    public function testStaleExpiredAndForeignTenantCannotActivateProposal(): void
    {
        $currencyId = $this->currency($this->supplierA);
        $stale = $this->decode($this->propose($currencyId, $this->tokenId, true));
        $this->db->pdo()->prepare('UPDATE currencies SET account_number = ? WHERE id = ?')
            ->execute(['1000000035', $currencyId]);
        $proof = $this->stepUp->issue($this->adminId, self::SESSION, MfaStepUpService::bankAccountProposalOperation('approve', (int) $stale['id']), 'totp');
        $response = $this->action->approve($this->admin($this->supplierA, ['step_up_token' => $proof]), new Response(), ['id' => (int) $stale['id']]);
        self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('stale', $this->decode($response)['error']['code'] ?? null);
        self::assertSame('1000000035', $this->bankRow($currencyId)['account_number']);

        $freshId = $this->currency($this->supplierA);
        $expired = $this->decode($this->propose($freshId, $this->tokenId, true));
        $this->db->pdo()->prepare('UPDATE bank_account_change_proposals SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = ?')
            ->execute([(int) $expired['id']]);
        $expiredProof = $this->stepUp->issue($this->adminId, self::SESSION, MfaStepUpService::bankAccountProposalOperation('approve', (int) $expired['id']), 'totp');
        $expiredResponse = $this->action->approve($this->admin($this->supplierA, ['step_up_token' => $expiredProof]), new Response(), ['id' => (int) $expired['id']]);
        self::assertSame(409, $expiredResponse->getStatusCode(), (string) $expiredResponse->getBody());
        self::assertSame(self::OLD_ACCOUNT, $this->bankRow($freshId)['account_number']);

        $other = $this->currency($this->supplierB);
        $cross = $this->propose($other, $this->tokenId, true);
        self::assertSame(404, $cross->getStatusCode(), (string) $cross->getBody());
        self::assertSame(self::OLD_ACCOUNT, $this->bankRow($other)['account_number']);
    }

    public function testValidationDuplicateRateLimitAndMissingCapability(): void
    {
        $currencyId = $this->currency($this->supplierA);
        $invalid = $this->propose($currencyId, $this->tokenId, true, ['label' => 'jiné pole']);
        self::assertSame(422, $invalid->getStatusCode(), (string) $invalid->getBody());

        $plain = $this->propose($currencyId, $this->plainTokenId, false);
        self::assertSame(403, $plain->getStatusCode(), (string) $plain->getBody());
        self::assertSame(self::OLD_ACCOUNT, $this->bankRow($currencyId)['account_number']);

        $first = $this->propose($currencyId, $this->tokenId, true);
        self::assertSame(201, $first->getStatusCode(), (string) $first->getBody());
        $mails = count($this->mail->sent);
        $duplicate = $this->propose($currencyId, $this->tokenId, true);
        self::assertSame(200, $duplicate->getStatusCode(), (string) $duplicate->getBody());
        self::assertFalse($this->decode($duplicate)['created']);
        self::assertSame($mails, count($this->mail->sent), 'Stejný čekající návrh nesmí poslat další e-mail.');

        $accountant = $this->action->list($this->staff($this->supplierA, 'accountant'), new Response());
        self::assertSame(403, $accountant->getStatusCode());

        for ($i = 0; $i < 4; $i++) {
            $next = $this->currency($this->supplierA);
            $created = $this->propose($next, $this->tokenId, true, ['account_number' => '200000000' . $i, 'bank_code' => '0100']);
            self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        }
        $limited = $this->propose($this->currency($this->supplierA), $this->tokenId, true, ['account_number' => '2000000099', 'bank_code' => '0100']);
        self::assertSame(429, $limited->getStatusCode(), (string) $limited->getBody());
    }

    private function seed(): void
    {
        $pdo = $this->db->pdo();
        $countryId = (int) ($pdo->query('SELECT id FROM countries ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($countryId === 0) {
            $pdo->exec("INSERT INTO countries (iso2, iso3, name_cs, name_en, is_eu) VALUES ('CZ', 'CZE', 'Česko', 'Czechia', 1)");
            $countryId = (int) $pdo->lastInsertId();
        }
        $vatRateId = (int) $pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $vatRateId);
        $this->supplierA = $this->supplier($pdo, $countryId, $vatRateId, '__TEST návrh A');
        $this->supplierB = $this->supplier($pdo, $countryId, $vatRateId, '__TEST návrh B');
        $adminRole = (int) $pdo->query("SELECT id FROM roles WHERE system_key = 'admin' AND role_type = 'staff' LIMIT 1")->fetchColumn();
        $accountantRole = (int) $pdo->query("SELECT id FROM roles WHERE system_key = 'accountant' LIMIT 1")->fetchColumn();
        $superRole = (int) $pdo->query("SELECT id FROM roles WHERE system_key = 'superadmin' LIMIT 1")->fetchColumn();
        self::assertGreaterThan(0, $adminRole);
        $suffix = bin2hex(random_bytes(4));
        $this->adminEmail = 'admin-' . $suffix . '@example.test';
        $this->accountantEmail = 'accountant-' . $suffix . '@example.test';
        $this->outsiderEmail = 'super-' . $suffix . '@example.test';
        $this->adminId = $this->user($pdo, $this->adminEmail, 'Správce návrhu', $adminRole);
        $this->accountantId = $this->user($pdo, $this->accountantEmail, 'Účetní návrhu', $accountantRole);
        $this->outsiderId = $this->user($pdo, $this->outsiderEmail, 'Super mimo firmu', $superRole);
        $pdo->prepare('INSERT INTO user_suppliers (user_id, supplier_id, role_id) VALUES (?, ?, ?)')
            ->execute([$this->adminId, $this->supplierA, $adminRole]);
        $pdo->prepare('INSERT INTO user_suppliers (user_id, supplier_id, role_id) VALUES (?, ?, ?)')
            ->execute([$this->accountantId, $this->supplierA, $accountantRole]);
        $this->tokenId = $this->token($pdo, $this->adminId, $this->supplierA, true, 'mi_pat_prop');
        $this->plainTokenId = $this->token($pdo, $this->adminId, $this->supplierA, false, 'mi_pat_plain');
        $currencyId = $this->invoiceCurrencyId = $this->currency($this->supplierA);
        $client = $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, currency_default_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $client->execute([$this->supplierA, '__TEST klient návrhu', 'Testovací 1', 'Praha', '11000', $countryId, $currencyId]);
        $clientId = (int) $pdo->lastInsertId();
        $invoice = $pdo->prepare(
            'INSERT INTO invoices (supplier_id, client_id, currency_id, invoice_type, issue_date, due_date, status, bank_snapshot)
             VALUES (?, ?, ?, \'invoice\', \'2026-01-15\', \'2026-01-29\', \'issued\', ?)'
        );
        $invoice->execute([
            $this->supplierA,
            $clientId,
            $currencyId,
            json_encode(['account_number' => self::OLD_ACCOUNT, 'bank_code' => '0100'], JSON_THROW_ON_ERROR),
        ]);
        $this->invoiceId = (int) $pdo->lastInsertId();
    }

    private function supplier(PDO $pdo, int $countryId, int $vatRateId, string $name): int
    {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $stmt = $pdo->prepare(
            'INSERT INTO supplier (company_name, city, street, zip, country_id, email, default_currency_id, default_vat_rate_id)
             VALUES (?, ?, ?, ?, ?, ?, 0, ?)'
        );
        $stmt->execute([$name, 'Praha', 'Testovací 1', '11000', $countryId, 'firma@example.test', $vatRateId]);
        $id = (int) $pdo->lastInsertId();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        $currencyId = $this->currency($id);
        $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([$currencyId, $id]);

        return $id;
    }

    private function user(PDO $pdo, string $email, string $name, int $roleId): int
    {
        $columns = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('role', $columns, true)) {
            $pdo->prepare('INSERT INTO users (email, password_hash, name, role, role_id, is_active, locale) VALUES (?, ?, ?, \'accountant\', ?, 1, \'cs\')')
                ->execute([$email, 'not-a-login', $name, $roleId]);
        } else {
            $pdo->prepare('INSERT INTO users (email, password_hash, name, role_id, is_active, locale) VALUES (?, ?, ?, ?, 1, \'cs\')')
                ->execute([$email, 'not-a-login', $name, $roleId]);
        }

        return (int) $pdo->lastInsertId();
    }

    private function token(PDO $pdo, int $userId, int $supplierId, bool $allow, string $prefix): int
    {
        $pdo->prepare(
            'INSERT INTO api_tokens (user_id, supplier_id, name, token_hash, prefix, scope, allow_bank_account_propose)
             VALUES (?, ?, ?, ?, ?, \'read_write\', ?)'
        )->execute([$userId, $supplierId, $prefix, hash('sha256', $prefix . random_bytes(8)), $prefix, $allow ? 1 : 0]);

        return (int) $pdo->lastInsertId();
    }

    private function currency(int $supplierId): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, account_number, bank_code)
             VALUES (?, \'CZK\', \'Test účet\', \'Kč\', \'koruna\', \'koruna\', 2, 1, ?, \'0100\')'
        )->execute([$supplierId, self::OLD_ACCOUNT]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $body */
    private function propose(int $currencyId, int $tokenId, bool $allow, array $body = []): ResponseInterface
    {
        $body += ['account_number' => self::NEW_ACCOUNT, 'bank_code' => self::NEW_BANK];

        return $this->action->create(
            $this->bearer($this->supplierA, $tokenId, $allow)->withParsedBody($body),
            new Response(),
            ['id' => $currencyId],
        );
    }

    /** @param array<string,mixed> $body */
    private function admin(int $supplierId, array $body): \Psr\Http\Message\ServerRequestInterface
    {
        return $this->staff($supplierId, 'admin')
            ->withParsedBody($body)
            ->withAttribute(AuthMiddleware::ATTR_TOKEN, self::SESSION);
    }

    private function staff(int $supplierId, string $systemKey): \Psr\Http\Message\ServerRequestInterface
    {
        $userId = $systemKey === 'admin' ? $this->adminId : $this->accountantId;

        return (new ServerRequestFactory())->createServerRequest('POST', '/api/settings/currencies/bank-change-proposals/1/approve', ['REMOTE_ADDR' => '203.0.113.15'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $userId])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute('auth.effective_role', new EffectiveRole(1, $systemKey, 'staff', true, [], $systemKey));
    }

    private function bearer(int $supplierId, int $tokenId, bool $allow): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', '/api/settings/currencies/' . $supplierId . '/bank-change-proposals', ['REMOTE_ADDR' => '203.0.113.15'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->adminId])
            ->withAttribute(AuthMiddleware::ATTR_API_TOKEN, [
                'id' => $tokenId,
                'prefix' => $allow ? 'mi_pat_prop' : 'mi_pat_plain',
                'scope' => 'read_write',
                'supplier_id' => $this->supplierA,
                'allow_bank_account_propose' => $allow ? 1 : 0,
            ])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId);
    }

    /** @return array{account_number:?string,bank_code:?string,bank_name:?string,iban:?string,bic:?string} */
    private function bankRow(int $currencyId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT account_number, bank_code, bank_name, iban, bic FROM currencies WHERE id = ?');
        $stmt->execute([$currencyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return $row;
    }

    private function invoiceSnapshot(): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT bank_snapshot FROM invoices WHERE id = ?');
        $stmt->execute([$this->invoiceId]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    /** @return array<string,mixed> */
    private function decode(ResponseInterface $response): array
    {
        $response->getBody()->rewind();

        return (array) json_decode((string) $response->getBody()->getContents(), true);
    }

    private function assertNotified(string $email, string $account): void
    {
        $hits = array_filter($this->mail->sent, static fn (array $item): bool => $item['email'] === $email);
        self::assertNotEmpty($hits, 'Správce firmy má dostat vyrozumění.');
        $vars = array_values($hits)[0]['vars'];
        self::assertStringContainsString($account, (string) $vars['comparison']);
    }

    private function assertNotNotified(string $email): void
    {
        foreach ($this->mail->sent as $item) {
            self::assertNotSame($email, $item['email']);
        }
    }

    private function assertAuditRedacted(int $proposalId, string $account, string $bankCode): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT payload FROM activity_log WHERE action = ? AND entity_id = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(['bank_account_proposal.created', $proposalId]);
        $payload = (string) $stmt->fetchColumn();
        self::assertStringNotContainsString($account, $payload);
        self::assertStringNotContainsString($bankCode, $payload);
        self::assertStringContainsString('[REDACTED]', $payload);
    }
}
