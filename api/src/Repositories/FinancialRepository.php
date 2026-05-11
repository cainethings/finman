<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use RuntimeException;

final class FinancialRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    public function dashboard(int $userId): array
    {
        return [
            'user' => $this->user($userId),
            'balance' => $this->balance($userId),
            'spending_trend' => $this->spendingTrend($userId),
            'category_breakdown' => $this->categoryBreakdown($userId),
            'transactions' => $this->transactions($userId),
            'budgets' => $this->budgets($userId),
            'goals' => $this->goals($userId),
            'recurring' => $this->recurringPayments($userId),
            'insights' => $this->latestInsights($userId),
        ];
    }

    public function user(int $userId): array
    {
        $stmt = $this->database->pdo()->prepare('SELECT id, name, email FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user) {
            throw new RuntimeException('User not found', 404);
        }
        return ['id' => (int) $user['id'], 'name' => $user['name'], 'email' => $user['email']];
    }

    public function transactions(int $userId): array
    {
        $stmt = $this->database->pdo()->prepare(
            'SELECT id, account_id, category_id, type, amount, merchant, notes, occurred_on, tags
             FROM transactions WHERE user_id = ? ORDER BY occurred_on DESC, id DESC LIMIT 50'
        );
        $stmt->execute([$userId]);
        return array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['account_id'] = (int) $row['account_id'];
            $row['category_id'] = $row['category_id'] ? (int) $row['category_id'] : null;
            $row['amount'] = (float) $row['amount'];
            $row['tags'] = $row['tags'] ? json_decode($row['tags'], true, 512, JSON_THROW_ON_ERROR) : [];
            return $row;
        }, $stmt->fetchAll());
    }

    public function createTransaction(int $userId, array $payload): array
    {
        $pdo = $this->database->pdo();
        $accountId = $this->defaultAccountId($userId);
        $stmt = $pdo->prepare(
            'INSERT INTO transactions (user_id, account_id, category_id, type, amount, merchant, notes, occurred_on, tags, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );
        $stmt->execute([
            $userId,
            $accountId,
            $payload['category_id'] ?? null,
            $payload['type'],
            $payload['amount'],
            $payload['merchant'],
            $payload['notes'] ?? null,
            $payload['occurred_on'],
            json_encode($payload['tags'] ?? [], JSON_THROW_ON_ERROR),
        ]);

        return ['id' => (int) $pdo->lastInsertId()];
    }

    public function updateTransaction(int $userId, int $transactionId, array $payload): array
    {
        $stmt = $this->database->pdo()->prepare(
            'UPDATE transactions
             SET merchant = ?, amount = ?, type = ?, occurred_on = ?, notes = ?, category_id = ?, tags = ?, updated_at = NOW()
             WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([
            $payload['merchant'],
            $payload['amount'],
            $payload['type'],
            $payload['occurred_on'],
            $payload['notes'] ?? null,
            $payload['category_id'] ?? null,
            json_encode($payload['tags'] ?? [], JSON_THROW_ON_ERROR),
            $transactionId,
            $userId,
        ]);

        return ['id' => $transactionId, 'updated' => $stmt->rowCount() > 0];
    }

    public function deleteTransaction(int $userId, int $transactionId): array
    {
        $stmt = $this->database->pdo()->prepare('DELETE FROM transactions WHERE id = ? AND user_id = ?');
        $stmt->execute([$transactionId, $userId]);
        return ['deleted' => $stmt->rowCount() > 0];
    }

    public function budgets(int $userId): array
    {
        $stmt = $this->database->pdo()->prepare(
            'SELECT b.id, c.name AS category_name, b.limit_amount,
                    COALESCE(SUM(CASE WHEN t.type = "expense" THEN t.amount ELSE 0 END), 0) AS spent_amount
             FROM budgets b
             INNER JOIN categories c ON c.id = b.category_id
             LEFT JOIN transactions t ON t.category_id = b.category_id
                AND t.user_id = b.user_id
                AND DATE_FORMAT(t.occurred_on, "%Y-%m") = DATE_FORMAT(CURDATE(), "%Y-%m")
             WHERE b.user_id = ?
             GROUP BY b.id, c.name, b.limit_amount
             ORDER BY spent_amount DESC'
        );
        $stmt->execute([$userId]);
        return array_map(static fn(array $row): array => [
            'id' => (int) $row['id'],
            'category_name' => $row['category_name'],
            'limit_amount' => (float) $row['limit_amount'],
            'spent_amount' => (float) $row['spent_amount'],
        ], $stmt->fetchAll());
    }

    public function goals(int $userId): array
    {
        $stmt = $this->database->pdo()->prepare('SELECT id, name, target_amount, current_amount, due_on FROM goals WHERE user_id = ? ORDER BY id DESC');
        $stmt->execute([$userId]);
        return array_map(static fn(array $row): array => [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'target_amount' => (float) $row['target_amount'],
            'current_amount' => (float) $row['current_amount'],
            'due_on' => $row['due_on'],
        ], $stmt->fetchAll());
    }

    public function recurringPayments(int $userId): array
    {
        $stmt = $this->database->pdo()->prepare('SELECT id, merchant, amount, frequency, next_due_on FROM recurring_payments WHERE user_id = ? ORDER BY next_due_on ASC');
        $stmt->execute([$userId]);
        return array_map(static fn(array $row): array => [
            'id' => (int) $row['id'],
            'merchant' => $row['merchant'],
            'amount' => (float) $row['amount'],
            'frequency' => $row['frequency'],
            'next_due_on' => $row['next_due_on'],
        ], $stmt->fetchAll());
    }

    public function latestInsights(int $userId): array
    {
        $stmt = $this->database->pdo()->prepare(
            'SELECT id, title, summary_text, severity, confidence, suggested_actions
             FROM ai_insights WHERE user_id = ? ORDER BY created_at DESC LIMIT 6'
        );
        $stmt->execute([$userId]);
        return array_map(static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'title' => $row['title'],
                'summary_text' => $row['summary_text'],
                'severity' => $row['severity'],
                'confidence' => (float) $row['confidence'],
                'suggested_actions' => json_decode($row['suggested_actions'], true, 512, JSON_THROW_ON_ERROR),
            ];
        }, $stmt->fetchAll());
    }

    public function saveInsight(int $userId, array $insight): void
    {
        $stmt = $this->database->pdo()->prepare(
            'INSERT INTO ai_insights (user_id, title, summary_text, severity, confidence, suggested_actions, related_transaction_ids, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $userId,
            $insight['title'],
            $insight['summary_text'],
            $insight['severity'],
            $insight['confidence'],
            json_encode($insight['suggested_actions'], JSON_THROW_ON_ERROR),
            json_encode($insight['related_transaction_ids'] ?? [], JSON_THROW_ON_ERROR),
        ]);
    }

    public function createChatThread(int $userId, string $title): int
    {
        $stmt = $this->database->pdo()->prepare('INSERT INTO chat_threads (user_id, title, created_at) VALUES (?, ?, NOW())');
        $stmt->execute([$userId, $title]);
        return (int) $this->database->pdo()->lastInsertId();
    }

    public function addChatMessage(int $threadId, string $role, string $content): int
    {
        $stmt = $this->database->pdo()->prepare('INSERT INTO chat_messages (thread_id, role, content, created_at) VALUES (?, ?, ?, NOW())');
        $stmt->execute([$threadId, $role, $content]);
        return (int) $this->database->pdo()->lastInsertId();
    }

    public function threadMessages(int $userId, int $threadId): array
    {
        $query = $this->database->pdo()->prepare(
            'SELECT m.id, m.role, m.content, m.created_at
             FROM chat_messages m
             INNER JOIN chat_threads t ON t.id = m.thread_id
             WHERE t.user_id = ? AND t.id = ?
             ORDER BY m.id ASC'
        );
        $query->execute([$userId, $threadId]);
        return array_map(static fn(array $row): array => [
            'id' => (int) $row['id'],
            'role' => $row['role'],
            'content' => $row['content'],
            'created_at' => $row['created_at'],
        ], $query->fetchAll());
    }

    public function statementUploads(int $userId): array
    {
        $stmt = $this->database->pdo()->prepare('SELECT id, filename, status, uploaded_at FROM statement_uploads WHERE user_id = ? ORDER BY id DESC');
        $stmt->execute([$userId]);
        return array_map(static fn(array $row): array => [
            'id' => (int) $row['id'],
            'filename' => $row['filename'],
            'status' => $row['status'],
            'uploaded_at' => $row['uploaded_at'],
        ], $stmt->fetchAll());
    }

    public function statementRows(int $userId, int $statementId): array
    {
        $stmt = $this->database->pdo()->prepare(
            'SELECT r.id, r.occurred_on, r.description, r.amount, r.balance, c.name AS category_name, r.confidence
             FROM statement_rows r
             INNER JOIN statement_uploads s ON s.id = r.statement_upload_id
             LEFT JOIN categories c ON c.id = r.category_id
             WHERE s.user_id = ? AND s.id = ?
             ORDER BY r.occurred_on DESC, r.id DESC'
        );
        $stmt->execute([$userId, $statementId]);
        return array_map(static fn(array $row): array => [
            'id' => (int) $row['id'],
            'occurred_on' => $row['occurred_on'],
            'description' => $row['description'],
            'amount' => (float) $row['amount'],
            'balance' => $row['balance'] !== null ? (float) $row['balance'] : null,
            'category_name' => $row['category_name'],
            'confidence' => (float) $row['confidence'],
        ], $stmt->fetchAll());
    }

    public function createStatementUpload(int $userId, string $filename, string $path): int
    {
        $stmt = $this->database->pdo()->prepare(
            'INSERT INTO statement_uploads (user_id, filename, storage_path, status, uploaded_at)
             VALUES (?, ?, ?, "parsed", NOW())'
        );
        $stmt->execute([$userId, $filename, $path]);
        return (int) $this->database->pdo()->lastInsertId();
    }

    public function insertStatementRow(int $statementId, int $userId, array $row): void
    {
        $stmt = $this->database->pdo()->prepare(
            'INSERT INTO statement_rows (statement_upload_id, user_id, occurred_on, description, amount, balance, category_id, confidence, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $statementId,
            $userId,
            $row['occurred_on'],
            $row['description'],
            $row['amount'],
            $row['balance'],
            $row['category_id'] ?? null,
            $row['confidence'],
        ]);
    }

    public function confirmStatement(int $userId, int $statementId): void
    {
        $rows = $this->statementRows($userId, $statementId);
        foreach ($rows as $row) {
            $this->createTransaction($userId, [
                'type' => $row['amount'] < 0 ? 'expense' : 'income',
                'amount' => abs($row['amount']),
                'merchant' => $row['description'],
                'occurred_on' => $row['occurred_on'],
                'category_id' => null,
                'tags' => ['pdf-import'],
            ]);
        }

        $stmt = $this->database->pdo()->prepare('UPDATE statement_uploads SET status = "confirmed" WHERE id = ? AND user_id = ?');
        $stmt->execute([$statementId, $userId]);
    }

    public function statementPath(int $userId, int $statementId): string
    {
        $stmt = $this->database->pdo()->prepare('SELECT storage_path FROM statement_uploads WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$statementId, $userId]);
        $path = $stmt->fetchColumn();
        if (!$path) {
            throw new RuntimeException('Statement not found', 404);
        }
        return (string) $path;
    }

    public function financialContext(int $userId): array
    {
        return [
            'balance' => $this->balance($userId),
            'transactions' => array_slice($this->transactions($userId), 0, 20),
            'budgets' => $this->budgets($userId),
            'goals' => $this->goals($userId),
            'recurring_payments' => $this->recurringPayments($userId),
        ];
    }

    private function balance(int $userId): float
    {
        $stmt = $this->database->pdo()->prepare(
            'SELECT COALESCE(SUM(CASE WHEN type = "expense" THEN -amount ELSE amount END), 0) AS balance
             FROM transactions WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        return (float) $stmt->fetchColumn();
    }

    private function spendingTrend(int $userId): array
    {
        $stmt = $this->database->pdo()->prepare(
            'SELECT DATE_FORMAT(occurred_on, "%d %b") AS day, SUM(amount) AS amount
             FROM transactions
             WHERE user_id = ? AND type = "expense"
             GROUP BY DATE(occurred_on)
             ORDER BY DATE(occurred_on) DESC
             LIMIT 14'
        );
        $stmt->execute([$userId]);
        return array_reverse(array_map(static fn(array $row): array => [
            'day' => $row['day'],
            'amount' => (float) $row['amount'],
        ], $stmt->fetchAll()));
    }

    private function categoryBreakdown(int $userId): array
    {
        $stmt = $this->database->pdo()->prepare(
            'SELECT COALESCE(c.name, "Unsorted") AS name, SUM(t.amount) AS value
             FROM transactions t
             LEFT JOIN categories c ON c.id = t.category_id
             WHERE t.user_id = ? AND t.type = "expense"
             GROUP BY c.name
             ORDER BY value DESC
             LIMIT 6'
        );
        $stmt->execute([$userId]);
        return array_map(static fn(array $row): array => [
            'name' => $row['name'],
            'value' => (float) $row['value'],
        ], $stmt->fetchAll());
    }

    private function defaultAccountId(int $userId): int
    {
        $stmt = $this->database->pdo()->prepare('SELECT id FROM accounts WHERE user_id = ? ORDER BY id ASC LIMIT 1');
        $stmt->execute([$userId]);
        $existing = $stmt->fetchColumn();
        if ($existing) {
            return (int) $existing;
        }

        $this->database->pdo()->prepare(
            'INSERT INTO accounts (user_id, name, type, created_at, updated_at) VALUES (?, "Primary Wallet", "bank", NOW(), NOW())'
        )->execute([$userId]);

        return (int) $this->database->pdo()->lastInsertId();
    }
}
