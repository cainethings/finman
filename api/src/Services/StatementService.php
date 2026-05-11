<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Repositories\FinancialRepository;
use RuntimeException;

final class StatementService
{
    public function __construct(
        private readonly Database $database,
        private readonly FinancialRepository $repository,
        private readonly OpenAIService $openAIService
    ) {
    }

    public function upload(int $userId, array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Statement upload failed.', 422);
        }

        $storageDir = dirname(__DIR__, 2) . '/storage/uploads';
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0775, true);
        }

        $targetName = uniqid('statement_', true) . '.pdf';
        $targetPath = $storageDir . '/' . $targetName;
        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            throw new RuntimeException('Could not store uploaded statement.', 500);
        }

        $statementId = $this->repository->createStatementUpload($userId, $file['name'], $targetPath);
        $rows = $this->parsePdf($targetPath);

        foreach ($rows as $row) {
            $this->repository->insertStatementRow($statementId, $userId, $row);
        }

        return ['statement_id' => $statementId, 'rows_imported' => count($rows)];
    }

    public function analyze(int $userId, int $statementId): array
    {
        $rows = $this->repository->statementRows($userId, $statementId);
        return $this->openAIService->analyzeStatement($rows);
    }

    private function parsePdf(string $path): array
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('Could not read statement.', 500);
        }

        preg_match_all('/\(([^()]*)\)|([A-Za-z0-9,.\-\/ ]{5,})/', $content, $matches);
        $chunks = array_values(array_filter(array_map('trim', array_merge($matches[1], $matches[2]))));
        $lines = preg_split('/[\r\n]+/', implode("\n", $chunks)) ?: [];

        $rows = [];
        foreach ($lines as $line) {
            $parsed = $this->parseLine($line);
            if ($parsed !== null) {
                $rows[] = $parsed;
            }
        }

        if ($rows === []) {
            $rows[] = [
                'occurred_on' => date('Y-m-d'),
                'description' => 'Manual review needed',
                'amount' => 0,
                'balance' => null,
                'confidence' => 0.2,
            ];
        }

        return $rows;
    }

    private function parseLine(string $line): ?array
    {
        $line = preg_replace('/\s+/', ' ', trim($line)) ?? '';
        if ($line === '') {
            return null;
        }

        if (!preg_match('/(?P<date>\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4})/', $line, $dateMatch)) {
            return null;
        }

        preg_match_all('/-?\d[\d,]*\.?\d{0,2}/', $line, $numbers);
        $values = array_map(
            static fn(string $value): float => (float) str_replace(',', '', $value),
            $numbers[0]
        );

        if ($values === []) {
            return null;
        }

        $amount = count($values) >= 2 ? $values[count($values) - 2] : $values[count($values) - 1];
        $balance = count($values) >= 2 ? $values[count($values) - 1] : null;
        $description = trim(str_replace($dateMatch[0], '', preg_replace('/-?\d[\d,]*\.?\d{0,2}/', '', $line) ?? $line));

        return [
            'occurred_on' => $this->normalizeDate($dateMatch['date']),
            'description' => $description !== '' ? $description : 'Imported statement row',
            'amount' => $amount,
            'balance' => $balance,
            'confidence' => strlen($description) > 5 ? 0.78 : 0.48,
        ];
    }

    private function normalizeDate(string $raw): string
    {
        $normalized = str_replace('-', '/', $raw);
        $parts = explode('/', $normalized);
        if (strlen($parts[2]) === 2) {
            $parts[2] = '20' . $parts[2];
        }
        return sprintf('%04d-%02d-%02d', (int) $parts[2], (int) $parts[1], (int) $parts[0]);
    }
}
