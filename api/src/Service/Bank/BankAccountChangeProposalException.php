<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

/**
 * Očekávaná chyba návrhu změny bankovního spojení. Akce ji mapuje na 4xx.
 */
final class BankAccountChangeProposalException extends \RuntimeException
{
    /**
     * @param array<string,mixed> $extra
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 400,
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }
}
