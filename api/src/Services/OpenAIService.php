<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\FinancialRepository;

final class OpenAIService
{
    public function __construct(private readonly FinancialRepository $repository)
    {
    }

    public function generateInsights(int $userId): array
    {
        $context = $this->repository->financialContext($userId);
        $fallback = $this->fallbackInsights($context);
        $apiKey = $_ENV['OPENAI_API_KEY'] ?? null;

        if (!$apiKey || !function_exists('curl_init')) {
            return $fallback;
        }

        $prompt = [
            'role' => 'system',
            'content' => 'You are a personal finance coach. Separate facts from suggestions. Avoid legal, tax, or investment advice. Return strict JSON with an array named insights.',
        ];

        $userMessage = [
            'role' => 'user',
            'content' => json_encode([
                'task' => 'Generate 3 concise, actionable money insights with severity, confidence, suggested_actions, and related_transaction_ids.',
                'context' => $context,
            ], JSON_THROW_ON_ERROR),
        ];

        $payload = [
            'model' => $_ENV['OPENAI_MODEL'] ?? 'gpt-4.1-mini',
            'response_format' => ['type' => 'json_object'],
            'messages' => [$prompt, $userMessage],
        ];

        $response = $this->request($payload, $apiKey);
        $content = $response['choices'][0]['message']['content'] ?? null;
        if (!$content) {
            return $fallback;
        }

        $decoded = json_decode($content, true);
        return is_array($decoded['insights'] ?? null) ? $decoded['insights'] : $fallback;
    }

    public function chat(int $userId, array $messages): string
    {
        $context = $this->repository->financialContext($userId);
        $apiKey = $_ENV['OPENAI_API_KEY'] ?? null;
        if (!$apiKey || !function_exists('curl_init')) {
            return $this->fallbackChat($context, $messages);
        }

        $formatted = [[
            'role' => 'system',
            'content' => 'You are a conversational money coach. Use only supplied user data, state uncertainty clearly, and give corrective and improvement tips without making autonomous decisions.',
        ], [
            'role' => 'system',
            'content' => json_encode(['financial_context' => $context], JSON_THROW_ON_ERROR),
        ]];

        foreach ($messages as $message) {
            $formatted[] = ['role' => $message['role'], 'content' => $message['content']];
        }

        $payload = [
            'model' => $_ENV['OPENAI_MODEL'] ?? 'gpt-4.1-mini',
            'messages' => $formatted,
        ];

        $response = $this->request($payload, $apiKey);
        return $response['choices'][0]['message']['content']
            ?? $this->fallbackChat($context, $messages);
    }

    public function analyzeStatement(array $rows): array
    {
        $ambiguous = array_values(array_filter($rows, static fn(array $row): bool => $row['confidence'] < 0.75));
        if ($ambiguous === []) {
            return ['summary' => 'Statement rows parsed with high confidence.', 'suggestions' => []];
        }

        return [
            'summary' => 'Some statement rows may need a manual category review.',
            'suggestions' => array_map(
                static fn(array $row): string => sprintf('Review "%s" on %s.', $row['description'], $row['occurred_on']),
                array_slice($ambiguous, 0, 5)
            ),
        ];
    }

    private function request(array $payload, string $apiKey): array
    {
        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
            CURLOPT_TIMEOUT => 30,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);

        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($decoded) ? $decoded : [];
    }

    private function fallbackInsights(array $context): array
    {
        $expenseTransactions = array_values(array_filter(
            $context['transactions'],
            static fn(array $transaction): bool => $transaction['type'] === 'expense'
        ));
        $topExpense = $expenseTransactions[0] ?? null;
        return [
            [
                'title' => 'Review your biggest recent outflow',
                'summary_text' => $topExpense
                    ? sprintf('Your latest major expense was %s at $%.2f. Confirm whether it fits this month’s priorities.', $topExpense['merchant'], $topExpense['amount'])
                    : 'Add a few transactions to unlock pattern-based recommendations.',
                'severity' => 'medium',
                'confidence' => 0.68,
                'suggested_actions' => ['Tag non-essential purchases', 'Set a category cap', 'Check renewals'],
                'related_transaction_ids' => $topExpense ? [$topExpense['id']] : [],
            ],
            [
                'title' => 'Recurring payment cleanup',
                'summary_text' => 'Compare subscriptions against actual usage and cancel anything inactive before the next billing cycle.',
                'severity' => 'low',
                'confidence' => 0.64,
                'suggested_actions' => ['Audit subscriptions', 'Pause duplicates', 'Move yearly plans to reminders'],
                'related_transaction_ids' => [],
            ],
            [
                'title' => 'Protect your savings runway',
                'summary_text' => 'Push a fixed amount into your active savings goal right after income lands to reduce end-of-month drift.',
                'severity' => 'low',
                'confidence' => 0.61,
                'suggested_actions' => ['Create a transfer reminder', 'Round up spare cash', 'Review goal target date'],
                'related_transaction_ids' => [],
            ],
        ];
    }

    private function fallbackChat(array $context, array $messages): string
    {
        $latest = end($messages);
        $question = strtolower((string) ($latest['content'] ?? ''));
        $budgetCount = count($context['budgets']);
        if (str_contains($question, 'budget')) {
            return "You have {$budgetCount} tracked budgets right now. I’d review the categories closest to their limit first, then tighten one non-essential bucket for the next cycle.";
        }
        return 'I can help interpret your spending, budgets, goals, and imported statements. Ask about unusual expenses, savings gaps, or which category looks riskiest this month.';
    }
}
