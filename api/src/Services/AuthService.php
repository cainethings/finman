<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Request;
use RuntimeException;

final class AuthService
{
    public function __construct(private readonly Database $database)
    {
    }

    public function requestOtp(string $email): array
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Enter a valid email address.', 422);
        }

        $pdo = $this->database->pdo();
        $user = $pdo->prepare('SELECT id, name, email FROM users WHERE email = ? LIMIT 1');
        $user->execute([$email]);
        $account = $user->fetch();

        if (!$account) {
            $insertUser = $pdo->prepare('INSERT INTO users (name, email, created_at, updated_at) VALUES (?, ?, NOW(), NOW())');
            $insertUser->execute([strtok($email, '@') ?: 'FinMan User', $email]);
            $userId = (int) $pdo->lastInsertId();
        } else {
            $userId = (int) $account['id'];
        }

        $otp = (string) random_int(100000, 999999);
        $otpHash = password_hash($otp, PASSWORD_DEFAULT);
        $expiresAt = (new \DateTimeImmutable('+10 minutes'))->format('Y-m-d H:i:s');

        $pdo->prepare('UPDATE otp_codes SET consumed_at = NOW() WHERE user_id = ? AND consumed_at IS NULL')
            ->execute([$userId]);
        $pdo->prepare('INSERT INTO otp_codes (user_id, code_hash, expires_at, created_at) VALUES (?, ?, ?, NOW())')
            ->execute([$userId, $otpHash, $expiresAt]);

        $response = ['message' => 'OTP sent to email'];
        if ($this->debugEnabled()) {
            $response['debug_notes'] = [
                'mail_transport_implemented' => false,
                'mail_status' => 'OTP is saved in the database, but SMTP sending is not implemented in this codebase yet.',
                'otp_saved_for_user_id' => $userId,
                'otp_expires_at' => $expiresAt,
                'mail_config' => [
                    'driver' => $_ENV['MAIL_DRIVER'] ?? null,
                    'from_address' => $_ENV['MAIL_FROM_ADDRESS'] ?? null,
                    'reply_to' => $_ENV['MAIL_REPLY_TO'] ?? null,
                    'smtp_host' => $_ENV['SMTP_HOST'] ?? null,
                    'smtp_port' => $_ENV['SMTP_PORT'] ?? null,
                    'smtp_encryption' => $_ENV['SMTP_ENCRYPTION'] ?? null,
                    'smtp_username' => $_ENV['SMTP_USERNAME'] ?? null,
                    'smtp_password_configured' => !empty($_ENV['SMTP_PASSWORD']),
                ],
            ];
        }
        if (($_ENV['APP_ENV'] ?? 'local') !== 'production') {
            $response['otp_preview'] = $otp;
        }

        return $response;
    }

    public function verifyOtp(string $email, string $otp): array
    {
        $pdo = $this->database->pdo();
        $query = $pdo->prepare(
            'SELECT u.id, u.name, u.email, o.id AS otp_id, o.code_hash, o.expires_at
             FROM users u
             INNER JOIN otp_codes o ON o.user_id = u.id
             WHERE u.email = ? AND o.consumed_at IS NULL
             ORDER BY o.id DESC
             LIMIT 1'
        );
        $query->execute([strtolower(trim($email))]);
        $row = $query->fetch();

        if (!$row || !password_verify($otp, $row['code_hash'])) {
            throw new RuntimeException('Invalid OTP.', 401);
        }

        if (strtotime($row['expires_at']) < time()) {
            throw new RuntimeException('OTP expired.', 401);
        }

        $pdo->prepare('UPDATE otp_codes SET consumed_at = NOW() WHERE id = ?')->execute([$row['otp_id']]);

        return [
            'access_token' => $this->issueToken((int) $row['id']),
            'user' => [
                'id' => (int) $row['id'],
                'name' => $row['name'],
                'email' => $row['email'],
            ],
        ];
    }

    public function refresh(Request $request): array
    {
        $userId = $this->userIdFromRequest($request);
        return ['access_token' => $this->issueToken($userId)];
    }

    public function logout(Request $request): array
    {
        $this->userIdFromRequest($request);
        return ['message' => 'Logged out'];
    }

    public function userIdFromRequest(Request $request): int
    {
        $token = $request->bearerToken();
        if (!$token) {
            throw new RuntimeException('Missing bearer token.', 401);
        }

        [$userId, $expires, $signature] = explode('.', $token) + [null, null, null];
        $secret = $_ENV['APP_KEY'] ?? 'finman-dev-secret';
        $expected = hash_hmac('sha256', sprintf('%s.%s', $userId, $expires), $secret);

        if (!$userId || !$expires || !$signature || !hash_equals($expected, $signature)) {
            throw new RuntimeException('Invalid access token.', 401);
        }

        if ((int) $expires < time()) {
            throw new RuntimeException('Access token expired.', 401);
        }

        return (int) $userId;
    }

    private function issueToken(int $userId): string
    {
        $expires = time() + 60 * 60 * 8;
        $payload = sprintf('%d.%d', $userId, $expires);
        $secret = $_ENV['APP_KEY'] ?? 'finman-dev-secret';
        return $payload . '.' . hash_hmac('sha256', $payload, $secret);
    }

    private function debugEnabled(): bool
    {
        $value = $_ENV['APP_DEBUG'] ?? null;
        if ($value === null) {
            return false;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
