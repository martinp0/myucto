<?php

declare(strict_types=1);

namespace MyInvoice\Action\Settings;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Bank\BankAccountChangeProposalException;
use MyInvoice\Service\Bank\BankAccountChangeProposalService;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Návrh změny bankovního spojení. Token návrh jen podá nebo stáhne.
 * Schválení a zamítnutí jsou session správce firmy a čerstvý step-up.
 */
final class BankAccountChangeProposalAction
{
    public function __construct(
        private readonly BankAccountChangeProposalService $proposals,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function create(Request $request, Response $response, array $args): Response
    {
        if (!RequestAuthorization::isBearerAuth($request)) {
            return Json::error($response, 'bearer_required', 'Návrh bankovního spojení podává API token.', 403);
        }
        if (!RequestAuthorization::tokenAllowsBankAccountProposal($request)) {
            return Json::error($response, 'proposal_not_allowed', 'Token nemá schopnost navrhovat bankovní spojení.', 403);
        }
        $token = (array) $request->getAttribute(AuthMiddleware::ATTR_API_TOKEN, []);
        if ((string) ($token['scope'] ?? '') !== 'read_write') {
            return Json::error($response, 'insufficient_scope', 'Návrh vyžaduje token se scope read_write.', 403);
        }
        $supplierId = $this->supplierId($request);
        $bound = $token['supplier_id'] ?? null;
        if ($bound !== null && (int) $bound !== $supplierId) {
            return Json::error($response, 'forbidden_supplier', 'Token je vázaný na jinou firmu.', 403);
        }
        $currencyId = (int) ($args['id'] ?? 0);
        if ($currencyId <= 0) {
            return Json::error($response, 'validation_failed', 'Neplatné id účtu.', 400);
        }
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);

        return $this->respond($response, function () use ($request, $supplierId, $currencyId, $token, $user): array {
            $created = $this->proposals->create(
                $supplierId,
                $currencyId,
                (int) ($token['id'] ?? 0),
                (int) ($user['id'] ?? 0),
                (string) ($token['prefix'] ?? ''),
                $this->ip($request),
                (array) ($request->getParsedBody() ?? []),
            );

            return [$created['created'] === true ? 201 : 200, $created];
        });
    }

    public function list(Request $request, Response $response): Response
    {
        $supplierId = $this->supplierId($request);
        $onlyToken = $this->tokenScope($request, $response);
        if ($onlyToken instanceof Response) {
            return $onlyToken;
        }

        return $this->respond($response, function () use ($supplierId, $onlyToken): array {
            return [200, ['items' => $this->proposals->listForSupplier($supplierId, $onlyToken)]];
        });
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->supplierId($request);
        $onlyToken = $this->tokenScope($request, $response);
        if ($onlyToken instanceof Response) {
            return $onlyToken;
        }

        return $this->respond($response, function () use ($supplierId, $onlyToken, $args): array {
            return [200, $this->proposals->show($supplierId, (int) ($args['id'] ?? 0), $onlyToken)];
        });
    }

    public function approve(Request $request, Response $response, array $args): Response
    {
        $denied = $this->adminSession($request, $response);
        if ($denied !== null) {
            return $denied;
        }
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $body = (array) ($request->getParsedBody() ?? []);

        return $this->respond($response, function () use ($request, $args, $user, $body): array {
            return [200, $this->proposals->approve(
                $this->supplierId($request),
                (int) ($args['id'] ?? 0),
                (int) ($user['id'] ?? 0),
                (string) $request->getAttribute(AuthMiddleware::ATTR_TOKEN, ''),
                trim((string) ($body['step_up_token'] ?? '')),
                $this->ip($request),
            )];
        });
    }

    public function reject(Request $request, Response $response, array $args): Response
    {
        $denied = $this->adminSession($request, $response);
        if ($denied !== null) {
            return $denied;
        }
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $body = (array) ($request->getParsedBody() ?? []);

        return $this->respond($response, function () use ($request, $args, $user, $body): array {
            return [200, $this->proposals->reject(
                $this->supplierId($request),
                (int) ($args['id'] ?? 0),
                (int) ($user['id'] ?? 0),
                (string) $request->getAttribute(AuthMiddleware::ATTR_TOKEN, ''),
                trim((string) ($body['step_up_token'] ?? '')),
                $this->ip($request),
                isset($body['reason']) ? (string) $body['reason'] : null,
            )];
        });
    }

    public function withdraw(Request $request, Response $response, array $args): Response
    {
        if (!RequestAuthorization::isBearerAuth($request) || !RequestAuthorization::tokenAllowsBankAccountProposal($request)) {
            return Json::error($response, 'proposal_not_allowed', 'Návrh smí stáhnout jen token, který ho podal.', 403);
        }
        $token = (array) $request->getAttribute(AuthMiddleware::ATTR_API_TOKEN, []);
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);

        return $this->respond($response, function () use ($request, $args, $token, $user): array {
            return [200, $this->proposals->withdraw(
                $this->supplierId($request),
                (int) ($args['id'] ?? 0),
                (int) ($token['id'] ?? 0),
                (int) ($user['id'] ?? 0),
                $this->ip($request),
            )];
        });
    }

    private function adminSession(Request $request, Response $response): ?Response
    {
        if (!RequestAuthorization::isSessionAuth($request)) {
            return Json::error($response, 'token_write_forbidden', 'Schválení i zamítnutí dělá správce ve webovém rozhraní.', 403);
        }
        if (!RequestAuthorization::isCompanyAdmin($request)) {
            return Json::error($response, 'forbidden', 'Návrh vyřizuje jen správce firmy.', 403);
        }

        return null;
    }

    /** @return int|null|Response null = celá firma (session správce), int = jen vlastní návrhy tokenu */
    private function tokenScope(Request $request, Response $response): int|null|Response
    {
        if (RequestAuthorization::isSessionAuth($request)) {
            if (!RequestAuthorization::isCompanyAdmin($request)) {
                return Json::error($response, 'forbidden', 'Návrhy vidí jen správce firmy.', 403);
            }

            return null;
        }
        if (!RequestAuthorization::isBearerAuth($request) || !RequestAuthorization::tokenAllowsBankAccountProposal($request)) {
            return Json::error($response, 'proposal_not_allowed', 'Token nemá schopnost navrhovat bankovní spojení.', 403);
        }
        $token = (array) $request->getAttribute(AuthMiddleware::ATTR_API_TOKEN, []);

        return (int) ($token['id'] ?? 0);
    }

    private function supplierId(Request $request): int
    {
        return (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
    }

    private function ip(Request $request): ?string
    {
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());

        return $ip !== '' ? $ip : null;
    }

    /**
     * @param callable(): array{0:int,1:array<string,mixed>} $call
     */
    private function respond(Response $response, callable $call): Response
    {
        try {
            [$status, $body] = $call();

            return Json::ok($response, $body, $status);
        } catch (BankAccountChangeProposalException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->status, $e->extra);
        }
    }
}
