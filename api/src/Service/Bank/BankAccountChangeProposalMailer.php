<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

use MyInvoice\Service\Mail\Mailer;

/**
 * Vyrozumění správců firmy. Chyba doručení jednoho adresáta nesmí shodit
 * už uložený návrh ani jeho vyřízení.
 */
class BankAccountChangeProposalMailer
{
    public function __construct(private readonly Mailer $mailer) {}

    /**
     * @param list<array{email:string,locale:string}> $recipients
     * @param array<string,mixed> $vars
     */
    public function notify(array $recipients, array $vars, int $supplierId): void
    {
        foreach ($recipients as $recipient) {
            $email = trim($recipient['email']);
            if ($email === '') {
                continue;
            }
            $locale = $recipient['locale'] === 'en' ? 'en' : 'cs';
            try {
                $this->deliver($locale, $email, $vars, $supplierId);
            } catch (\Throwable) {
                // Další správce má zprávu dostat i když jednomu schránka selže.
            }
        }
    }

    /**
     * @param array<string,mixed> $vars
     */
    protected function deliver(string $locale, string $email, array $vars, int $supplierId): void
    {
        $this->mailer->sendTemplate(
            'bank_account_change_proposal',
            $locale,
            [$email],
            $vars,
            null,
            [],
            [],
            [],
            null,
            null,
            $supplierId,
        );
    }
}
