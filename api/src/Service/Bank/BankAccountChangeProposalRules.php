<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

/**
 * Čistá pravidla návrhu změny bankovního spojení. Bez databáze, ať jdou
 * ověřit i tam, kde testovací DB není po ruce.
 */
final class BankAccountChangeProposalRules
{
    public const EXPIRY_DAYS = 7;
    public const TOKEN_HOURLY_LIMIT = 5;
    public const SUPPLIER_DAILY_LIMIT = 20;
    public const REASON_MAX = 200;

    /** @var list<string> */
    public const FIELDS = ['account_number', 'bank_code', 'bank_name', 'iban', 'bic'];

    /**
     * Z těla požadavku vybere jen bankovní pole. Cokoli jiného je chyba.
     *
     * @param array<string,mixed> $body
     * @return array<string,?string> normalizované hodnoty jen přítomných polí
     */
    public static function proposedFields(array $body): array
    {
        $unknown = array_diff(array_keys($body), self::FIELDS);
        if ($unknown !== []) {
            throw new BankAccountChangeProposalException(
                'validation_failed',
                'Návrh smí nést jen bankovní pole účtu.',
                422,
                ['fields' => array_values($unknown)],
            );
        }
        $errors = CurrencyBankAccountFields::validationErrors($body);
        if ($errors !== []) {
            throw new BankAccountChangeProposalException(
                'validation_failed',
                (string) reset($errors)[0],
                422,
                ['fields' => $errors],
            );
        }
        $out = [];
        foreach (self::FIELDS as $field) {
            if (!array_key_exists($field, $body)) {
                continue;
            }
            $out[$field] = CurrencyBankAccountFields::normalized($body[$field]);
        }
        if ($out === []) {
            throw new BankAccountChangeProposalException(
                'validation_failed',
                'Návrh musí změnit aspoň jedno bankovní pole.',
                422,
            );
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $baseline
     * @param array<string,?string> $proposed
     */
    public static function changesAnything(array $baseline, array $proposed): bool
    {
        foreach ($proposed as $field => $value) {
            if (!self::same($baseline[$field] ?? null, $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     */
    public static function sameBankState(array $left, array $right): bool
    {
        foreach (self::FIELDS as $field) {
            if (!self::same($left[$field] ?? null, $right[$field] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string,?string> $proposed
     */
    public static function fingerprint(int $supplierId, int $currencyId, array $proposed): string
    {
        $normalized = [];
        foreach (self::FIELDS as $field) {
            if (array_key_exists($field, $proposed)) {
                $normalized[$field] = $proposed[$field];
            }
        }

        return hash('sha256', json_encode([
            'supplier_id' => $supplierId,
            'currency_id' => $currencyId,
            'fields' => $normalized,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public static function same(mixed $left, mixed $right): bool
    {
        return self::text($left) === self::text($right);
    }

    public static function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
