<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Auth;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Repository\PasskeyCredentialRepository;
use MyInvoice\Service\Auth\MfaPolicyService;
use MyInvoice\Service\Auth\MfaStepUpProofStore;
use MyInvoice\Service\Auth\MfaStepUpService;
use MyInvoice\Service\Auth\StepUpOperationException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class BankAccountProposalStepUpTest extends TestCase
{
    public function testFreshTotpOrPasskeyCanConfirmOneProposalAndRecoveryCannot(): void
    {
        $stepUp = new MfaStepUpService(
            $this->createStub(MfaStepUpProofStore::class),
            new MfaPolicyService(new Config(['auth' => ['allowed_mfa_methods' => ['passkey', 'totp']]])),
            $this->createStub(PasskeyCredentialRepository::class),
        );
        $approve = MfaStepUpService::bankAccountProposalOperation('approve', 15);
        $reject = MfaStepUpService::bankAccountProposalOperation('reject', 15);

        self::assertSame($approve, $stepUp->assertAllowed(7, $approve, 'totp'));
        self::assertSame($reject, $stepUp->assertAllowed(7, $reject, 'passkey'));
        try {
            $stepUp->assertAllowed(7, $approve, 'recovery');
            self::fail('Záložní kód nesmí návrh schválit.');
        } catch (StepUpOperationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testUnknownDecisionIsNotAStepUpOperation(): void
    {
        $stepUp = new MfaStepUpService(
            $this->createStub(MfaStepUpProofStore::class),
            new MfaPolicyService(new Config(['auth' => ['allowed_mfa_methods' => ['passkey', 'totp']]])),
            $this->createStub(PasskeyCredentialRepository::class),
        );
        try {
            $stepUp->assertAllowed(7, 'bank_account_proposal.approve', 'totp');
            self::fail('Proof bez id návrhu nesmí projít.');
        } catch (StepUpOperationException) {
            $this->addToAssertionCount(1);
        }
    }
}
