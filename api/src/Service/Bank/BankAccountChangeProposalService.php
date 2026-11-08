<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

use MyInvoice\Repository\BankStatementOwnershipResolver;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\MfaStepUpService;
use MyInvoice\Service\Auth\OneTimeTokenException;
use MyInvoice\Service\Auth\SecurityClock;
use MyInvoice\Service\Auth\StepUpOperationException;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Čekající návrh změny bankovního spojení. Zápis do `currencies` je jen ve
 * schválení, a to až po účelovém step-up správce firmy.
 */
final class BankAccountChangeProposalService
{
    public function __construct(
        private readonly Connection $db,
        private readonly ActivityLogger $activity,
        private readonly InvoicePdfRenderer $pdf,
        private readonly BankStatementOwnershipResolver $ownership,
        private readonly BankAccountChangeProposalMailer $mailer,
        private readonly MfaStepUpService $stepUp,
        private readonly SecurityClock $clock,
    ) {}

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function create(
        int $supplierId,
        int $currencyId,
        int $tokenId,
        int $userId,
        string $tokenPrefix,
        ?string $ip,
        array $body,
    ): array {
        $proposed = BankAccountChangeProposalRules::proposedFields($body);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $this->lockSupplier($pdo, $supplierId);
            $current = $this->lockCurrency($pdo, $supplierId, $currencyId);
            if (!BankAccountChangeProposalRules::changesAnything($current, $proposed)) {
                throw new BankAccountChangeProposalException(
                    'validation_failed',
                    'Návrh se od uloženého účtu neliší.',
                    422,
                );
            }
            $this->assertAccountAvailable($supplierId, $proposed);
            $fingerprint = BankAccountChangeProposalRules::fingerprint($supplierId, $currencyId, $proposed);
            $existing = $this->findOpenDuplicate($pdo, $supplierId, $currencyId, $tokenId, $fingerprint);
            if ($existing !== null) {
                $pdo->commit();
                return $this->present($pdo, $existing, false);
            }
            $this->assertWithinRateLimit($pdo, $supplierId, $tokenId);

            $fields = array_keys($proposed);
            sort($fields);
            $stmt = $pdo->prepare(
                'INSERT INTO bank_account_change_proposals (
                    supplier_id, currency_id, api_token_id, requested_by_user_id, token_prefix, client_ip,
                    status, proposed_fields, fingerprint,
                    account_number, bank_code, bank_name, iban, bic,
                    baseline_account_number, baseline_bank_code, baseline_bank_name, baseline_iban, baseline_bic,
                    expires_at
                 ) VALUES (
                    ?, ?, ?, ?, ?, ?,
                    \'pending\', ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    DATE_ADD(NOW(), INTERVAL ? DAY)
                 )'
            );
            $stmt->execute([
                $supplierId,
                $currencyId,
                $tokenId,
                $userId,
                $tokenPrefix,
                $ip,
                implode(',', $fields),
                $fingerprint,
                $proposed['account_number'] ?? null,
                $proposed['bank_code'] ?? null,
                $proposed['bank_name'] ?? null,
                $proposed['iban'] ?? null,
                $proposed['bic'] ?? null,
                BankAccountChangeProposalRules::text($current['account_number'] ?? null),
                BankAccountChangeProposalRules::text($current['bank_code'] ?? null),
                BankAccountChangeProposalRules::text($current['bank_name'] ?? null),
                BankAccountChangeProposalRules::text($current['iban'] ?? null),
                BankAccountChangeProposalRules::text($current['bic'] ?? null),
                BankAccountChangeProposalRules::EXPIRY_DAYS,
            ]);
            $id = (int) $pdo->lastInsertId();
            $this->audit($userId, $supplierId, $currencyId, $id, 'bank_account_proposal.created', $fields, $proposed, $ip, $tokenId, $tokenPrefix);
            $row = $this->mustLoad($pdo, $supplierId, $id);
            $recipients = $this->adminRecipients($pdo, $supplierId);
            $pdo->commit();
        } catch (BankAccountChangeProposalException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $this->notify($recipients, $row, 'proposed', $supplierId, $userId, $ip);
        return $this->present($pdo, $row, true);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listForSupplier(int $supplierId, ?int $onlyTokenId): array
    {
        $pdo = $this->db->pdo();
        $this->expireDue($pdo, $supplierId, null);
        $sql = 'SELECT p.*, u.name AS requester_name, d.name AS decider_name, c.code AS currency_code
                  FROM bank_account_change_proposals p
                  JOIN currencies c ON c.id = p.currency_id AND c.supplier_id = p.supplier_id
                  LEFT JOIN users u ON u.id = p.requested_by_user_id
                  LEFT JOIN users d ON d.id = p.decision_user_id
                 WHERE p.supplier_id = ?';
        $params = [$supplierId];
        if ($onlyTokenId !== null) {
            $sql .= ' AND p.api_token_id = ?';
            $params[] = $onlyTokenId;
        }
        $sql .= ' ORDER BY p.status = \'pending\' DESC, p.id DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(fn (array $row): array => $this->present($pdo, $row, false), $rows);
    }

    /**
     * @return array<string,mixed>
     */
    public function show(int $supplierId, int $proposalId, ?int $onlyTokenId): array
    {
        $pdo = $this->db->pdo();
        $this->expireDue($pdo, $supplierId, $proposalId);
        $row = $this->loadVisible($pdo, $supplierId, $proposalId, $onlyTokenId);

        return $this->present($pdo, $row, false);
    }

    /**
     * @return array<string,mixed>
     */
    public function approve(
        int $supplierId,
        int $proposalId,
        int $userId,
        string $sessionToken,
        string $proofToken,
        ?string $ip,
    ): array {
        return $this->decide($supplierId, $proposalId, $userId, $sessionToken, $proofToken, $ip, 'approve', null);
    }

    /**
     * @return array<string,mixed>
     */
    public function reject(
        int $supplierId,
        int $proposalId,
        int $userId,
        string $sessionToken,
        string $proofToken,
        ?string $ip,
        ?string $reason,
    ): array {
        return $this->decide($supplierId, $proposalId, $userId, $sessionToken, $proofToken, $ip, 'reject', $reason);
    }

    /**
     * @return array<string,mixed>
     */
    public function withdraw(int $supplierId, int $proposalId, int $tokenId, int $userId, ?string $ip): array
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $this->lockSupplier($pdo, $supplierId);
            $preview = $this->lockProposal($pdo, $supplierId, $proposalId);
            $currency = $this->lockCurrency($pdo, $supplierId, (int) $preview['currency_id']);
            $row = $preview;
            if ((int) ($row['api_token_id'] ?? 0) !== $tokenId) {
                throw new BankAccountChangeProposalException('forbidden', 'Token smí stáhnout jen vlastní návrh.', 403);
            }
            if ((string) $row['status'] === 'pending' && (int) ($row['is_expired'] ?? 0) === 1) {
                $this->mark($pdo, $proposalId, 'expired', null, null);
                $this->audit($userId, $supplierId, (int) $row['currency_id'], $proposalId, 'bank_account_proposal.expired', $this->fieldList($row), $this->proposedFromRow($row), $ip, $tokenId, (string) $row['token_prefix']);
                $fresh = $this->mustLoad($pdo, $supplierId, $proposalId);
                $recipients = $this->adminRecipients($pdo, $supplierId);
                $pdo->commit();
                $this->notify($recipients, $fresh, 'expired', $supplierId, $userId, $ip);
                throw new BankAccountChangeProposalException('expired', 'Návrhu vypršela lhůta.', 409);
            }
            if ((string) $row['status'] !== 'pending') {
                throw new BankAccountChangeProposalException('already_decided', 'Návrh už není čekající.', 409);
            }
            $pdo->prepare(
                'UPDATE bank_account_change_proposals
                    SET status = \'withdrawn\', decided_at = NOW(), decision_user_id = ?
                  WHERE id = ? AND status = \'pending\''
            )->execute([$userId, $proposalId]);
            $this->audit(
                $userId,
                $supplierId,
                (int) $row['currency_id'],
                $proposalId,
                'bank_account_proposal.withdrawn',
                $this->fieldList($row),
                $this->proposedFromRow($row),
                $ip,
                $tokenId,
                (string) $row['token_prefix'],
            );
            $fresh = $this->mustLoad($pdo, $supplierId, $proposalId);
            $recipients = $this->adminRecipients($pdo, $supplierId);
            $pdo->commit();
        } catch (BankAccountChangeProposalException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        unset($currency);
        $this->notify($recipients, $fresh, 'withdrawn', $supplierId, $userId, $ip);

        return $this->present($pdo, $fresh, false);
    }

    /**
     * @return array<string,mixed>
     */
    private function decide(
        int $supplierId,
        int $proposalId,
        int $userId,
        string $sessionToken,
        string $proofToken,
        ?string $ip,
        string $decision,
        ?string $reason,
    ): array {
        if ($proofToken === '') {
            throw new BankAccountChangeProposalException(
                'step_up_required',
                'Vyřízení návrhu vyžaduje nové ověření.',
                401,
                ['methods' => ['passkey', 'totp']],
            );
        }
        $reason = $this->reason($reason);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $cutoff = $this->clock->capture($pdo);
            $this->lockSupplier($pdo, $supplierId);
            $preview = $this->lockProposal($pdo, $supplierId, $proposalId);
            $currency = $this->lockCurrency($pdo, $supplierId, (int) $preview['currency_id']);
            $row = $preview;
            if ((string) $row['status'] !== 'pending') {
                throw new BankAccountChangeProposalException('already_decided', 'Návrh už není čekající.', 409);
            }
            if ((int) ($row['is_expired'] ?? 0) === 1) {
                $this->mark($pdo, $proposalId, 'expired', null, null);
                $this->audit($userId, $supplierId, (int) $row['currency_id'], $proposalId, 'bank_account_proposal.expired', $this->fieldList($row), $this->proposedFromRow($row), $ip, (int) ($row['api_token_id'] ?? 0), (string) $row['token_prefix']);
                $fresh = $this->mustLoad($pdo, $supplierId, $proposalId);
                $recipients = $this->adminRecipients($pdo, $supplierId);
                $pdo->commit();
                $this->notify($recipients, $fresh, 'expired', $supplierId, $userId, $ip);
                throw new BankAccountChangeProposalException('expired', 'Návrhu vypršela lhůta.', 409);
            }
            if (!BankAccountChangeProposalRules::sameBankState($this->baselineFromRow($row), $currency)) {
                $this->mark($pdo, $proposalId, 'stale', null, null);
                $this->audit($userId, $supplierId, (int) $row['currency_id'], $proposalId, 'bank_account_proposal.stale', $this->fieldList($row), $this->proposedFromRow($row), $ip, (int) ($row['api_token_id'] ?? 0), (string) $row['token_prefix']);
                $fresh = $this->mustLoad($pdo, $supplierId, $proposalId);
                $recipients = $this->adminRecipients($pdo, $supplierId);
                $pdo->commit();
                $this->notify($recipients, $fresh, 'stale', $supplierId, $userId, $ip);
                throw new BankAccountChangeProposalException(
                    'stale',
                    'Účet se od podání návrhu změnil. Návrh už nelze potvrdit.',
                    409,
                );
            }

            $proposed = $this->proposedFromRow($row);
            if ($decision === 'approve') {
                $this->assertAccountAvailable($supplierId, $proposed);
                $operation = MfaStepUpService::bankAccountProposalOperation('approve', $proposalId);
                $this->stepUp->consumeInTransaction($pdo, $cutoff, $proofToken, $userId, $sessionToken, $operation);
                $sets = [];
                $params = [];
                foreach (BankAccountChangeProposalRules::FIELDS as $field) {
                    if (!array_key_exists($field, $proposed)) {
                        continue;
                    }
                    $sets[] = $field . ' = ?';
                    $params[] = $proposed[$field];
                }
                $params[] = (int) $row['currency_id'];
                $params[] = $supplierId;
                $pdo->prepare('UPDATE currencies SET ' . implode(', ', $sets) . ' WHERE id = ? AND supplier_id = ?')
                    ->execute($params);
                $invalidated = $this->pdf->invalidateByCurrency((int) $row['currency_id']);
                $registered = OwnBankAccountRegistrar::syncFromCurrency($pdo, $supplierId, (int) $row['currency_id'], $this->ownership);
                $this->mark($pdo, $proposalId, 'approved', $userId, null);
                $this->audit($userId, $supplierId, (int) $row['currency_id'], $proposalId, 'bank_account_proposal.approved', $this->fieldList($row), $proposed, $ip, (int) ($row['api_token_id'] ?? 0), (string) $row['token_prefix']);
                $this->activity->log('currency.updated', $userId, 'currency', (int) $row['currency_id'], [
                    'fields' => $this->fieldList($row),
                    'pdf_invalidated' => $invalidated,
                    'bank_registered' => $registered,
                    'proposal_id' => $proposalId,
                    'via' => 'bank_account_proposal',
                ], $ip, null, $supplierId);
                $event = 'approved';
            } else {
                $operation = MfaStepUpService::bankAccountProposalOperation('reject', $proposalId);
                $this->stepUp->consumeInTransaction($pdo, $cutoff, $proofToken, $userId, $sessionToken, $operation);
                $this->mark($pdo, $proposalId, 'rejected', $userId, $reason);
                $this->audit($userId, $supplierId, (int) $row['currency_id'], $proposalId, 'bank_account_proposal.rejected', $this->fieldList($row), $proposed, $ip, (int) ($row['api_token_id'] ?? 0), (string) $row['token_prefix']);
                $event = 'rejected';
            }
            $fresh = $this->mustLoad($pdo, $supplierId, $proposalId);
            $recipients = $this->adminRecipients($pdo, $supplierId);
            $pdo->commit();
        } catch (BankAccountChangeProposalException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        } catch (OneTimeTokenException|StepUpOperationException) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new BankAccountChangeProposalException(
                'step_up_proof_invalid',
                'Step-up ověření je neplatné nebo již bylo použito.',
                403,
            );
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $this->notify($recipients, $fresh, $event, $supplierId, $userId, $ip);

        return $this->present($pdo, $fresh, false);
    }

    /**
     * @param array<string,?string> $proposed
     */
    private function assertAccountAvailable(int $supplierId, array $proposed): void
    {
        $account = array_key_exists('account_number', $proposed) ? $proposed['account_number'] : null;
        $iban = array_key_exists('iban', $proposed) ? $proposed['iban'] : null;
        if ($account === null && $iban === null) {
            return;
        }
        if ($this->ownership->accountClaimedByOtherSupplier($supplierId, $account, $iban)) {
            throw new BankAccountChangeProposalException(
                'account_claimed',
                'Tento bankovní účet už je evidovaný u jiné firmy.',
                409,
            );
        }
        if ($this->ownership->accountBlockedByForeignStatements($supplierId, $account, $iban)) {
            throw new BankAccountChangeProposalException(
                'account_has_foreign_statements',
                'K tomuto účtu jsou v systému bankovní výpisy jiné firmy.',
                409,
            );
        }
    }

    private function assertWithinRateLimit(PDO $pdo, int $supplierId, int $tokenId): void
    {
        $tokenCount = $pdo->prepare(
            'SELECT COUNT(*) FROM bank_account_change_proposals
              WHERE api_token_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)'
        );
        $tokenCount->execute([$tokenId]);
        if ((int) $tokenCount->fetchColumn() >= BankAccountChangeProposalRules::TOKEN_HOURLY_LIMIT) {
            throw new BankAccountChangeProposalException(
                'rate_limited',
                'Token za poslední hodinu podal příliš mnoho návrhů.',
                429,
            );
        }
        $supplierCount = $pdo->prepare(
            'SELECT COUNT(*) FROM bank_account_change_proposals
              WHERE supplier_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)'
        );
        $supplierCount->execute([$supplierId]);
        if ((int) $supplierCount->fetchColumn() >= BankAccountChangeProposalRules::SUPPLIER_DAILY_LIMIT) {
            throw new BankAccountChangeProposalException(
                'rate_limited',
                'Firma za poslední den dostala příliš mnoho návrhů.',
                429,
            );
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findOpenDuplicate(PDO $pdo, int $supplierId, int $currencyId, int $tokenId, string $fingerprint): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM bank_account_change_proposals
              WHERE supplier_id = ? AND currency_id = ? AND api_token_id = ?
                AND fingerprint = ? AND status = \'pending\' AND expires_at > NOW()
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $currencyId, $tokenId, $fingerprint]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function lockSupplier(PDO $pdo, int $supplierId): void
    {
        $stmt = $pdo->prepare('SELECT id FROM supplier WHERE id = ? FOR UPDATE');
        $stmt->execute([$supplierId]);
        if ($stmt->fetchColumn() === false) {
            throw new BankAccountChangeProposalException('not_found', 'Firma nenalezena.', 404);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function lockCurrency(PDO $pdo, int $supplierId, int $currencyId): array
    {
        $stmt = $pdo->prepare(
            'SELECT id, code, account_number, bank_code, bank_name, iban, bic
               FROM currencies WHERE id = ? AND supplier_id = ? FOR UPDATE'
        );
        $stmt->execute([$currencyId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new BankAccountChangeProposalException('not_found', 'Měnový účet nenalezen.', 404);
        }

        return $row;
    }

    /**
     * @return array<string,mixed>
     */
    private function lockProposal(PDO $pdo, int $supplierId, int $proposalId): array
    {
        $stmt = $pdo->prepare(
            'SELECT p.*, (p.expires_at <= NOW()) AS is_expired
               FROM bank_account_change_proposals p
              WHERE p.id = ? AND p.supplier_id = ?
              FOR UPDATE'
        );
        $stmt->execute([$proposalId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new BankAccountChangeProposalException('not_found', 'Návrh nenalezen.', 404);
        }

        return $row;
    }

    private function mark(PDO $pdo, int $proposalId, string $status, ?int $userId, ?string $reason): void
    {
        $pdo->prepare(
            'UPDATE bank_account_change_proposals
                SET status = ?, decided_at = NOW(), decision_user_id = ?, decision_reason = ?
              WHERE id = ? AND status = \'pending\''
        )->execute([$status, $userId, $reason, $proposalId]);
    }

    private function expireDue(PDO $pdo, int $supplierId, ?int $proposalId): void
    {
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        $sql = 'SELECT * FROM bank_account_change_proposals
                 WHERE supplier_id = ? AND status = \'pending\' AND expires_at <= NOW()';
        $params = [$supplierId];
        if ($proposalId !== null) {
            $sql .= ' AND id = ?';
            $params[] = $proposalId;
        }
        $sql .= ' FOR UPDATE';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $due = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($due === []) {
            if ($own && $pdo->inTransaction()) {
                $pdo->commit();
            }
            return;
        }
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $due);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare(
            'UPDATE bank_account_change_proposals
                SET status = \'expired\', decided_at = NOW()
              WHERE status = \'pending\' AND id IN (' . $placeholders . ')'
        )->execute($ids);
        $recipients = $this->adminRecipients($pdo, $supplierId);
        if ($own && $pdo->inTransaction()) {
            $pdo->commit();
        }
        foreach ($due as $row) {
            $row['status'] = 'expired';
            $this->audit(0, $supplierId, (int) $row['currency_id'], (int) $row['id'], 'bank_account_proposal.expired', $this->fieldList($row), $this->proposedFromRow($row), null, (int) ($row['api_token_id'] ?? 0), (string) $row['token_prefix']);
            $this->notify($recipients, $row, 'expired', $supplierId, 0, null);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function loadVisible(PDO $pdo, int $supplierId, int $proposalId, ?int $onlyTokenId): array
    {
        $sql = 'SELECT p.*, u.name AS requester_name, d.name AS decider_name, c.code AS currency_code
                  FROM bank_account_change_proposals p
                  LEFT JOIN currencies c ON c.id = p.currency_id AND c.supplier_id = p.supplier_id
                  LEFT JOIN users u ON u.id = p.requested_by_user_id
                  LEFT JOIN users d ON d.id = p.decision_user_id
                 WHERE p.id = ? AND p.supplier_id = ?';
        $params = [$proposalId, $supplierId];
        if ($onlyTokenId !== null) {
            $sql .= ' AND p.api_token_id = ?';
            $params[] = $onlyTokenId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new BankAccountChangeProposalException('not_found', 'Návrh nenalezen.', 404);
        }

        return $row;
    }

    /**
     * @return array<string,mixed>
     */
    private function mustLoad(PDO $pdo, int $supplierId, int $proposalId): array
    {
        return $this->loadVisible($pdo, $supplierId, $proposalId, null);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function present(PDO $pdo, array $row, bool $created): array
    {
        if (!isset($row['currency_code'])) {
            $code = $pdo->prepare('SELECT code FROM currencies WHERE id = ? AND supplier_id = ?');
            $code->execute([(int) $row['currency_id'], (int) $row['supplier_id']]);
            $row['currency_code'] = $code->fetchColumn() ?: null;
            $name = $pdo->prepare('SELECT name FROM users WHERE id = ?');
            $name->execute([(int) ($row['requested_by_user_id'] ?? 0)]);
            $row['requester_name'] = $name->fetchColumn() ?: null;
        }
        $fields = $this->fieldList($row);
        $proposed = [];
        $baseline = [];
        foreach (BankAccountChangeProposalRules::FIELDS as $field) {
            $baseline[$field] = BankAccountChangeProposalRules::text($row['baseline_' . $field] ?? null);
            if (in_array($field, $fields, true)) {
                $proposed[$field] = BankAccountChangeProposalRules::text($row[$field] ?? null);
            }
        }

        return [
            'id' => (int) $row['id'],
            'supplier_id' => (int) $row['supplier_id'],
            'currency_id' => (int) $row['currency_id'],
            'currency_code' => $row['currency_code'] !== null ? (string) $row['currency_code'] : null,
            'status' => (string) $row['status'],
            'created' => $created,
            'created_at' => (string) $row['created_at'],
            'expires_at' => (string) $row['expires_at'],
            'decided_at' => $row['decided_at'] !== null ? (string) $row['decided_at'] : null,
            'decision_reason' => $row['decision_reason'] !== null ? (string) $row['decision_reason'] : null,
            'proposed' => $proposed,
            'baseline' => $baseline,
            'origin' => [
                'token_id' => $row['api_token_id'] !== null ? (int) $row['api_token_id'] : null,
                'token_prefix' => (string) $row['token_prefix'],
                'user_id' => $row['requested_by_user_id'] !== null ? (int) $row['requested_by_user_id'] : null,
                'user_name' => isset($row['requester_name']) && $row['requester_name'] !== false && $row['requester_name'] !== null
                    ? (string) $row['requester_name'] : null,
                'ip' => $row['client_ip'] !== null ? (string) $row['client_ip'] : null,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return list<string>
     */
    private function fieldList(array $row): array
    {
        $fields = array_values(array_filter(explode(',', (string) $row['proposed_fields'])));

        return array_values(array_intersect($fields, BankAccountChangeProposalRules::FIELDS));
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,?string>
     */
    private function proposedFromRow(array $row): array
    {
        $out = [];
        foreach ($this->fieldList($row) as $field) {
            $out[$field] = BankAccountChangeProposalRules::text($row[$field] ?? null);
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,?string>
     */
    private function baselineFromRow(array $row): array
    {
        $out = [];
        foreach (BankAccountChangeProposalRules::FIELDS as $field) {
            $out[$field] = BankAccountChangeProposalRules::text($row['baseline_' . $field] ?? null);
        }

        return $out;
    }

    /**
     * Správci firmy: členství v `user_suppliers` a role admin, admin_plus
     * nebo superadmin. Superadmin bez členství v téhle firmě zprávu nedostane.
     *
     * @return list<array{email:string,locale:string}>
     */
    private function adminRecipients(PDO $pdo, int $supplierId): array
    {
        $stmt = $pdo->prepare(
            'SELECT DISTINCT u.email, COALESCE(NULLIF(u.locale, \'\'), \'cs\') AS locale
               FROM user_suppliers us
               JOIN users u ON u.id = us.user_id AND u.is_active = 1
               JOIN roles r ON r.id = COALESCE(us.role_id, u.role_id)
              WHERE us.supplier_id = ?
                AND r.is_active = 1
                AND r.system_key IN (\'admin\', \'admin_plus\', \'superadmin\')
                AND u.email <> \'\''
        );
        $stmt->execute([$supplierId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'email' => (string) $row['email'],
                'locale' => (string) $row['locale'] === 'en' ? 'en' : 'cs',
            ];
        }

        return $out;
    }

    /**
     * @param list<array{email:string,locale:string}> $recipients
     * @param array<string,mixed> $row
     */
    private function notify(array $recipients, array $row, string $event, int $supplierId, int $userId, ?string $ip): void
    {
        if ($recipients === []) {
            return;
        }
        try {
            $this->mailer->notify($recipients, $this->mailVars($row, $event), $supplierId);
        } catch (\Throwable) {
            $this->activity->log('bank_account_proposal.notify_failed', $userId, 'bank_account_change_proposal', (int) $row['id'], [
                'event' => $event,
                'proposal_id' => (int) $row['id'],
            ], $ip, null, $supplierId);
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function mailVars(array $row, string $event): array
    {
        $lines = [];
        $fields = $this->fieldList($row);
        foreach ($fields as $field) {
            $lines[] = $field . ': '
                . (BankAccountChangeProposalRules::text($row['baseline_' . $field] ?? null) ?? '—')
                . ' → '
                . (BankAccountChangeProposalRules::text($row[$field] ?? null) ?? '—');
        }

        return [
            'event' => $event,
            'currency_code' => (string) ($row['currency_code'] ?? ''),
            'token_prefix' => (string) ($row['token_prefix'] ?? ''),
            'requester_name' => (string) ($row['requester_name'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'expires_at' => (string) ($row['expires_at'] ?? ''),
            'client_ip' => (string) ($row['client_ip'] ?? ''),
            'comparison' => implode("\n", $lines),
            'reason' => (string) ($row['decision_reason'] ?? ''),
        ];
    }

    /**
     * @param list<string> $fields
     * @param array<string,?string> $proposed
     */
    private function audit(
        int $userId,
        int $supplierId,
        int $currencyId,
        int $proposalId,
        string $action,
        array $fields,
        array $proposed,
        ?string $ip,
        int $tokenId,
        string $tokenPrefix,
    ): void {
        // account_number a iban ActivityLogger začerní. bank_code, bank_name a
        // bic v redakci nejsou, proto jejich hodnoty do auditu vůbec nedáváme.
        $this->activity->log($action, $userId, 'bank_account_change_proposal', $proposalId, [
            'proposal_id' => $proposalId,
            'currency_id' => $currencyId,
            'supplier_id' => $supplierId,
            'token_id' => $tokenId > 0 ? $tokenId : null,
            'token_prefix' => $tokenPrefix,
            'fields' => $fields,
            'account_number' => $proposed['account_number'] ?? null,
            'iban' => $proposed['iban'] ?? null,
        ], $ip, null, $supplierId);
    }

    private function reason(?string $reason): ?string
    {
        if ($reason === null) {
            return null;
        }
        $reason = trim($reason);
        if ($reason === '') {
            return null;
        }
        if (mb_strlen($reason) > BankAccountChangeProposalRules::REASON_MAX) {
            throw new BankAccountChangeProposalException(
                'validation_failed',
                'Důvod smí mít nejvýše 200 znaků.',
                422,
            );
        }

        return $reason;
    }
}
