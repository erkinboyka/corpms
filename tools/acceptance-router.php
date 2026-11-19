<?php

declare(strict_types=1);
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

$root = realpath((string) getenv('CORPMS_ACCEPTANCE_ROOT'));
$allowed = realpath(__DIR__.'/../storage/framework/acceptance');
if ($root === false || $allowed === false || ! str_starts_with(strtolower(str_replace('\\', '/', $root)), strtolower(str_replace('\\', '/', $allowed)).'/')) {
    http_response_code(503);
    exit('An isolated acceptance fixture is required');
}
$path = realpath(__DIR__.'/../public'.parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$public = realpath(__DIR__.'/../public');
if ($path !== false && is_file($path) && str_starts_with(strtolower($path), strtolower($public).DIRECTORY_SEPARATOR)) {
    return false;
}
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->booting(function () use ($root): void {
    config(['app.env' => 'acceptance', 'app.debug' => false, 'app.url' => 'http://127.0.0.1:8198',
        'database.default' => 'sqlite', 'database.connections.sqlite.database' => $root.'/database.sqlite',
        'database.connections.sqlite.url' => null, 'cache.default' => 'array', 'session.driver' => 'file',
        'session.files' => $root.'/sessions', 'session.domain' => null, 'session.secure' => false,
        'queue.default' => 'sync', 'mail.default' => 'array', 'corpms.ai.base_url' => '', 'corpms.ai.api_key' => '']);
    Http::preventStrayRequests();
});
$app->handleRequest(Request::capture());
