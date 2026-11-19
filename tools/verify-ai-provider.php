<?php

declare(strict_types=1);

use App\Support\SettingsService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$settings = app(SettingsService::class);
$settings->useCompany(null);
$base = rtrim((string) $settings->get('corpms.ai.base_url'), '/');
$key = (string) $settings->get('corpms.ai.api_key');
$model = (string) $settings->get('corpms.ai.model');
if ($base === '' || $key === '') {
    echo json_encode(['ok' => false, 'reason' => 'not_configured']).PHP_EOL;
    exit(2);
}
try {
    $response = Http::withToken($key)->timeout(30)->post($base.'/chat/completions', [
        'model' => $model, 'messages' => [['role' => 'user', 'content' => 'Reply with exactly OK.']], 'max_tokens' => 8, 'temperature' => 0,
    ]);
    $result = ['ok' => $response->successful(), 'status' => $response->status(), 'model' => $model,
        'provider_host' => parse_url($base, PHP_URL_HOST), 'error_code' => $response->json('error.code'),
        'answer_received' => is_string($response->json('choices.0.message.content'))];
    if (! $response->successful()) {
        // Error messages can contain provider-supplied request details; do not print them.
        $result['error_type'] = $response->json('error.type');
    }
    echo json_encode($result, JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit($response->successful() ? 0 : 1);
} catch (Throwable $exception) {
    echo json_encode(['ok' => false, 'reason' => 'transport_failure', 'exception' => get_class($exception)]).PHP_EOL;
    exit(1);
}
