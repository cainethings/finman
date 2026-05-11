<?php

declare(strict_types=1);

use App\Controllers\AiController;
use App\Controllers\AuthController;
use App\Controllers\FinanceController;
use App\Controllers\StatementController;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Repositories\FinancialRepository;
use App\Services\AuthService;
use App\Services\OpenAIService;
use App\Services\StatementService;

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    $baseDir = dirname(__DIR__) . '/src/';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relativeClass = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

$envFile = dirname(__DIR__) . '/.env';
if (is_file($envFile)) {
    $pairs = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($pairs as $pair) {
        if (str_starts_with(trim($pair), '#') || !str_contains($pair, '=')) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $pair, 2));
        $_ENV[$key] = trim($value, "\"'");
    }
}

$request = Request::capture();
$db = Database::fromEnv();
$repository = new FinancialRepository($db);
$authService = new AuthService($db);
$openAiService = new OpenAIService($repository);
$statementService = new StatementService($db, $repository, $openAiService);

$authController = new AuthController($authService);
$financeController = new FinanceController($repository);
$statementController = new StatementController($statementService, $repository);
$aiController = new AiController($repository, $openAiService);

$router = new Router();
$router->get('/health', static fn() => Response::json(['status' => 'ok']));
$router->post('/auth/request-otp', [$authController, 'requestOtp']);
$router->post('/auth/verify-otp', [$authController, 'verifyOtp']);
$router->post('/auth/refresh', [$authController, 'refresh']);
$router->post('/auth/logout', [$authController, 'logout']);

$router->get('/dashboard', fn(Request $request) => $financeController->dashboard($request, $authService));
$router->get('/transactions', fn(Request $request) => $financeController->transactions($request, $authService));
$router->post('/transactions', fn(Request $request) => $financeController->storeTransaction($request, $authService));
$router->put('/transactions/{id}', fn(Request $request, array $params) => $financeController->updateTransaction($request, $params, $authService));
$router->delete('/transactions/{id}', fn(Request $request, array $params) => $financeController->deleteTransaction($request, $params, $authService));
$router->get('/budgets', fn(Request $request) => $financeController->budgets($request, $authService));
$router->get('/goals', fn(Request $request) => $financeController->goals($request, $authService));
$router->get('/recurring-payments', fn(Request $request) => $financeController->recurring($request, $authService));

$router->get('/statements', fn(Request $request) => $statementController->index($request, $authService));
$router->post('/statements/upload', fn(Request $request) => $statementController->upload($request, $authService));
$router->get('/statements/{id}/rows', fn(Request $request, array $params) => $statementController->rows($request, $params, $authService));
$router->post('/statements/{id}/confirm', fn(Request $request, array $params) => $statementController->confirm($request, $params, $authService));
$router->post('/statements/{id}/analyze', fn(Request $request, array $params) => $statementController->analyze($request, $params, $authService));

$router->post('/ai/insights/generate', fn(Request $request) => $aiController->generateInsights($request, $authService));
$router->get('/ai/insights/latest', fn(Request $request) => $aiController->latestInsights($request, $authService));
$router->get('/ai/chat/threads', fn(Request $request) => $aiController->threadMessages($request, $authService));
$router->post('/ai/chat/message', fn(Request $request) => $aiController->message($request, $authService));

try {
    $router->dispatch($request);
} catch (Throwable $exception) {
    Response::json(
        ['message' => $exception->getMessage()],
        $exception->getCode() >= 400 ? $exception->getCode() : 500
    );
}
