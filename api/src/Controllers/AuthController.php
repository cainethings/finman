<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Services\AuthService;

final class AuthController
{
    public function __construct(private readonly AuthService $service)
    {
    }

    public function requestOtp(Request $request): array
    {
        return $this->service->requestOtp((string) ($request->body['email'] ?? ''));
    }

    public function verifyOtp(Request $request): array
    {
        return $this->service->verifyOtp(
            (string) ($request->body['email'] ?? ''),
            (string) ($request->body['otp'] ?? '')
        );
    }

    public function refresh(Request $request): array
    {
        return $this->service->refresh($request);
    }

    public function logout(Request $request): array
    {
        return $this->service->logout($request);
    }
}
