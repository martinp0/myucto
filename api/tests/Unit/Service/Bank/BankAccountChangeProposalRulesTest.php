<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank;

use MyInvoice\Service\Bank\BankAccountChangeProposalException;
use MyInvoice\Service\Bank\BankAccountChangeProposalRules;
use PHPUnit\Framework\TestCase;

final class BankAccountChangeProposalRulesTest extends TestCase
{
    public function testRejectsNonBankFieldsAndEmptyProposal(): void
    {
        try {
            BankAccountChangeProposalRules::proposedFields(['label' => 'jiný název']);
            self::fail('Cizí pole musí návrh odmítnout.');
        } catch (BankAccountChangeProposalException $e) {
            self::assertSame('validation_failed', $e->errorCode);
            self::assertSame(422, $e->status);
        }

        try {
            BankAccountChangeProposalRules::proposedFields([]);
            self::fail('Prázdný návrh nesmí projít.');
        } catch (BankAccountChangeProposalException $e) {
            self::assertSame(422, $e->status);
        }
    }

    public function testNormalizesOnlyProvidedFieldsAndDetectsNoop(): void
    {
        $proposed = BankAccountChangeProposalRules::proposedFields([
            'account_number' => ' 1000000005 ',
            'bank_code' => '0100',
        ]);
        self::assertSame([
            'account_number' => '1000000005',
            'bank_code' => '0100',
        ], $proposed);
        self::assertFalse(BankAccountChangeProposalRules::changesAnything([
            'account_number' => '1000000005',
            'bank_code' => '0100',
        ], $proposed));
        self::assertTrue(BankAccountChangeProposalRules::changesAnything([
            'account_number' => '1000000019',
            'bank_code' => '0100',
        ], $proposed));
    }

    public function testStaleComparisonIgnoresWhitespaceAndRequiresEveryField(): void
    {
        $left = [
            'account_number' => '1000000005',
            'bank_code' => '0100',
            'bank_name' => null,
            'iban' => '',
            'bic' => null,
        ];
        $right = [
            'account_number' => ' 1000000005 ',
            'bank_code' => '0100',
            'bank_name' => '',
            'iban' => null,
            'bic' => null,
        ];
        self::assertTrue(BankAccountChangeProposalRules::sameBankState($left, $right));
        $right['bic'] = 'TESTCZPP';
        self::assertFalse(BankAccountChangeProposalRules::sameBankState($left, $right));
    }

    public function testFingerprintIgnoresFieldOrder(): void
    {
        $a = BankAccountChangeProposalRules::fingerprint(4, 9, ['iban' => null, 'account_number' => '1000000005']);
        $b = BankAccountChangeProposalRules::fingerprint(4, 9, ['account_number' => '1000000005', 'iban' => null]);
        self::assertSame($a, $b);
        self::assertNotSame($a, BankAccountChangeProposalRules::fingerprint(4, 9, ['account_number' => '1000000019']));
    }
}
