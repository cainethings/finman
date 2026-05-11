<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class MailerService
{
    public function sendOtp(string $toEmail, string $otp, string $expiresAt): void
    {
        $driver = strtolower(trim((string) ($_ENV['MAIL_DRIVER'] ?? '')));
        if ($driver !== 'smtp') {
            throw new RuntimeException('Unsupported mail driver. Set MAIL_DRIVER=smtp.');
        }

        $host = trim((string) ($_ENV['SMTP_HOST'] ?? ''));
        $port = (int) ($_ENV['SMTP_PORT'] ?? 465);
        $encryption = strtolower(trim((string) ($_ENV['SMTP_ENCRYPTION'] ?? 'ssl')));
        $username = trim((string) ($_ENV['SMTP_USERNAME'] ?? ''));
        $password = (string) ($_ENV['SMTP_PASSWORD'] ?? '');
        $fromAddress = trim((string) ($_ENV['MAIL_FROM_ADDRESS'] ?? $username));
        $replyTo = trim((string) ($_ENV['MAIL_REPLY_TO'] ?? $fromAddress));

        if ($host === '' || $username === '' || $password === '' || $fromAddress === '') {
            throw new RuntimeException('SMTP configuration is incomplete.');
        }

        $transport = $encryption === 'ssl' ? 'ssl://' . $host : $host;
        $socket = @stream_socket_client(
            sprintf('%s:%d', $transport, $port),
            $errorNumber,
            $errorMessage,
            15
        );

        if (!is_resource($socket)) {
            throw new RuntimeException(sprintf('SMTP connection failed: %s (%d)', $errorMessage, $errorNumber));
        }

        stream_set_timeout($socket, 15);

        try {
            $this->expect($socket, [220]);
            $this->command($socket, 'EHLO finmaster.cainethings.com', [250]);

            if ($encryption === 'tls') {
                $this->command($socket, 'STARTTLS', [220]);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('Failed to enable TLS encryption for SMTP.');
                }
                $this->command($socket, 'EHLO finmaster.cainethings.com', [250]);
            }

            $this->command($socket, 'AUTH LOGIN', [334]);
            $this->command($socket, base64_encode($username), [334]);
            $this->command($socket, base64_encode($password), [235]);
            $this->command($socket, 'MAIL FROM:<' . $fromAddress . '>', [250]);
            $this->command($socket, 'RCPT TO:<' . $toEmail . '>', [250, 251]);
            $this->command($socket, 'DATA', [354]);
            $this->write($socket, $this->buildMessage($fromAddress, $replyTo, $toEmail, $otp, $expiresAt) . "\r\n.\r\n");
            $this->expect($socket, [250]);
            $this->command($socket, 'QUIT', [221]);
        } finally {
            fclose($socket);
        }
    }

    private function buildMessage(string $fromAddress, string $replyTo, string $toEmail, string $otp, string $expiresAt): string
    {
        $subject = 'FinMaster OTP Verification Code';
        $bodyLines = [
            'Hello,',
            '',
            'Your FinMaster verification code is: ' . $otp,
            'This code expires at: ' . $expiresAt,
            '',
            'If you did not request this code, you can ignore this email.',
            '',
            'Thanks,',
            'FinMaster',
        ];

        return implode("\r\n", [
            'From: FinMaster <' . $fromAddress . '>',
            'Reply-To: ' . $replyTo,
            'To: ' . $toEmail,
            'Subject: ' . $subject,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            '',
            implode("\r\n", $bodyLines),
        ]);
    }

    private function command($socket, string $command, array $expectedCodes): string
    {
        $this->write($socket, $command . "\r\n");
        return $this->expect($socket, $expectedCodes);
    }

    private function expect($socket, array $expectedCodes): string
    {
        $response = '';

        while (($line = fgets($socket)) !== false) {
            $response .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }

        if ($response === '') {
            throw new RuntimeException('SMTP server returned an empty response.');
        }

        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $expectedCodes, true)) {
            throw new RuntimeException('SMTP error: ' . trim($response));
        }

        return $response;
    }

    private function write($socket, string $data): void
    {
        $bytes = fwrite($socket, $data);
        if ($bytes === false || $bytes < strlen($data)) {
            throw new RuntimeException('Failed to write to SMTP socket.');
        }
    }
}
