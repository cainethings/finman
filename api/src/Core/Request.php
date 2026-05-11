<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $body,
        public readonly array $files,
        public readonly array $headers
    ) {
    }

    public static function capture(): self
    {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $rawBody = file_get_contents('php://input') ?: '';
        $decoded = json_decode($rawBody, true);
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        // Apache routes public API traffic through /api, but the app router
        // is registered without that prefix.
        if (str_starts_with($path, '/api/')) {
            $path = substr($path, 4);
        } elseif ($path === '/api') {
            $path = '/';
        }

        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $path,
            $_GET,
            is_array($decoded) ? $decoded : $_POST,
            $_FILES,
            is_array($headers) ? $headers : []
        );
    }

    public function bearerToken(): ?string
    {
        $header = $this->headers['Authorization'] ?? $this->headers['authorization'] ?? null;
        if (!$header || !str_starts_with($header, 'Bearer ')) {
          return null;
        }

        return substr($header, 7);
    }
}
