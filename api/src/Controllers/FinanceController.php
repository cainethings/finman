<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Repositories\FinancialRepository;
use App\Services\AuthService;
use RuntimeException;

final class FinanceController
{
    public function __construct(private readonly FinancialRepository $repository)
    {
    }

    public function dashboard(Request $request, AuthService $auth): array
    {
        return $this->repository->dashboard($auth->userIdFromRequest($request));
    }

    public function transactions(Request $request, AuthService $auth): array
    {
        return ['data' => $this->repository->transactions($auth->userIdFromRequest($request))];
    }

    public function storeTransaction(Request $request, AuthService $auth): array
    {
        return $this->repository->createTransaction(
            $auth->userIdFromRequest($request),
            $this->validatedPayload($request->body)
        );
    }

    public function updateTransaction(Request $request, array $params, AuthService $auth): array
    {
        return $this->repository->updateTransaction(
            $auth->userIdFromRequest($request),
            (int) $params['id'],
            $this->validatedPayload($request->body)
        );
    }

    public function deleteTransaction(Request $request, array $params, AuthService $auth): array
    {
        return $this->repository->deleteTransaction($auth->userIdFromRequest($request), (int) $params['id']);
    }

    public function budgets(Request $request, AuthService $auth): array
    {
        return ['data' => $this->repository->budgets($auth->userIdFromRequest($request))];
    }

    public function goals(Request $request, AuthService $auth): array
    {
        return ['data' => $this->repository->goals($auth->userIdFromRequest($request))];
    }

    public function recurring(Request $request, AuthService $auth): array
    {
        return ['data' => $this->repository->recurringPayments($auth->userIdFromRequest($request))];
    }

    private function validatedPayload(array $payload): array
    {
        if (!isset($payload['merchant'], $payload['amount'], $payload['type'], $payload['occurred_on'])) {
            throw new RuntimeException('Missing required transaction fields.', 422);
        }

        return [
            'merchant' => trim((string) $payload['merchant']),
            'amount' => (float) $payload['amount'],
            'type' => (string) $payload['type'],
            'occurred_on' => (string) $payload['occurred_on'],
            'category_id' => $payload['category_id'] ?? null,
            'notes' => $payload['notes'] ?? null,
            'tags' => $payload['tags'] ?? [],
        ];
    }
}
