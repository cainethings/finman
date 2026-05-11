<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Repositories\FinancialRepository;
use App\Services\AuthService;
use App\Services\StatementService;

final class StatementController
{
    public function __construct(
        private readonly StatementService $service,
        private readonly FinancialRepository $repository
    )
    {
    }

    public function index(Request $request, AuthService $auth): array
    {
        return ['data' => $this->repository->statementUploads($auth->userIdFromRequest($request))];
    }

    public function upload(Request $request, AuthService $auth): array
    {
        return $this->service->upload(
            $auth->userIdFromRequest($request),
            $request->files['statement'] ?? []
        );
    }

    public function rows(Request $request, array $params, AuthService $auth): array
    {
        return [
            'data' => $this->repository->statementRows($auth->userIdFromRequest($request), (int) $params['id'])
        ];
    }

    public function confirm(Request $request, array $params, AuthService $auth): array
    {
        $this->repository->confirmStatement($auth->userIdFromRequest($request), (int) $params['id']);
        return ['message' => 'Statement imported'];
    }

    public function analyze(Request $request, array $params, AuthService $auth): array
    {
        return $this->service->analyze($auth->userIdFromRequest($request), (int) $params['id']);
    }
}
