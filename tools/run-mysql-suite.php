<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$native = DB::connection()->getConfig();
if (! in_array($native['driver'], ['mysql', 'mariadb'], true)) {
    throw new RuntimeException('MySQL/MariaDB connection required');
}
$server = DB::connection()->getPdo();
$database = 'corpms_suite_'.bin2hex(random_bytes(6)).'_test';
$exit = 1;
try {
    $server->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '', 'DB_DATABASE' => $database,
        'DB_HOST' => $native['host'], 'DB_PORT' => (string) $native['port'], 'DB_USERNAME' => $native['username'],
        'DB_PASSWORD' => $native['password'], 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
        'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'AI_BASE_URL' => '', 'AI_API_KEY' => ''];
    $arguments = [PHP_BINARY, 'vendor/phpunit/phpunit/phpunit', '--log-junit', isset($argv[1]) ? 'storage/logs/closure-mysql-focused.xml' : 'storage/logs/closure-mysql.xml'];
    if (isset($argv[1])) {
        $arguments[] = '--filter';
        $arguments[] = $argv[1];
    }
    $process = new Process($arguments, dirname(__DIR__), $env, null, 600);
    $process->run(function ($type, $data): void {
        echo $data;
    });
    $exit = $process->getExitCode();
} finally {
    if (preg_match('/^corpms_suite_[a-f0-9]+_test$/', $database)) {
        $server->exec('DROP DATABASE IF EXISTS `'.$database.'`');
    }
}
exit($exit);
