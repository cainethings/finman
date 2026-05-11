<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Repositories\FinancialRepository;
use App\Services\AuthService;
use App\Services\OpenAIService;

final class AiController
{
    public function __construct(
        private readonly FinancialRepository $repository,
        private readonly OpenAIService $openAIService
    ) {
    }

    public function generateInsights(Request $request, AuthService $auth): array
    {
        $userId = $auth->userIdFromRequest($request);
        $insights = $this->openAIService->generateInsights($userId);
        foreach ($insights as $insight) {
            $this->repository->saveInsight($userId, $insight);
        }
        return ['data' => $this->repository->latestInsights($userId)];
    }

    public function latestInsights(Request $request, AuthService $auth): array
    {
        return ['data' => $this->repository->latestInsights($auth->userIdFromRequest($request))];
    }

    public function threadMessages(Request $request, AuthService $auth): array
    {
        $threadId = (int) ($request->query['thread_id'] ?? 0);
        return ['data' => $threadId > 0
            ? $this->repository->threadMessages($auth->userIdFromRequest($request), $threadId)
            : []];
    }

    public function message(Request $request, AuthService $auth): array
    {
        $userId = $auth->userIdFromRequest($request);
        $threadId = (int) ($request->body['thread_id'] ?? 0);
        $message = trim((string) ($request->body['message'] ?? ''));

        if ($threadId === 0) {
            $threadId = $this->repository->createChatThread($userId, 'Money coach chat');
        }

        $this->repository->addChatMessage($threadId, 'user', $message);
        $threadMessages = $this->repository->threadMessages($userId, $threadId);
        $answer = $this->openAIService->chat($userId, $threadMessages);
        $messageId = $this->repository->addChatMessage($threadId, 'assistant', $answer);

        return [
            'thread_id' => $threadId,
            'message' => [
                'id' => $messageId,
                'role' => 'assistant',
                'content' => $answer,
                'created_at' => date('Y-m-d H:i:s'),
            ],
        ];
    }
}
