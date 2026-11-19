<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$file = realpath($argv[1] ?? '');
$binary = $argv[2] ?? 'mysql';
if ($file === false || ! is_file($file) || strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'sql') {
    fwrite(STDERR, 'An existing MySQL .sql backup is required'.PHP_EOL);
    exit(2);
}
$suffix = bin2hex(random_bytes(6));
$database = 'corpms_restore_'.$suffix.'_test';
$username = 'corpms_restore_'.$suffix;
$password = bin2hex(random_bytes(24));
$server = null;
$userCreated = false;
try {
    $source = DB::connection();
    $native = $source->getConfig();
    if (! in_array($native['driver'], ['mysql', 'mariadb'], true)) {
        throw new RuntimeException('MySQL/MariaDB is required');
    }
    $server = $source->getPdo();
    $server->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $account = $server->quote($username)."@'%'";
    $server->exec('CREATE USER '.$account.' IDENTIFIED BY '.$server->quote($password));
    $userCreated = true;
    // The import identity cannot modify the source database, users, or grants.
    $server->exec('GRANT ALL PRIVILEGES ON `'.$database.'`.* TO '.$account);
    $process = new Process([$binary, '--host='.$native['host'], '--port='.$native['port'], '--user='.$username,
        '--default-character-set=utf8mb4', $database], dirname(__DIR__), ['MYSQL_PWD' => $password], null, 120);
    $input = fopen($file, 'rb');
    try {
        $process->setInput($input);
        $process->run();
    } finally {
        fclose($input);
    }
    if (! $process->isSuccessful()) {
        throw new RuntimeException('Backup import failed; source database was not an import target');
    }
    $restore = new PDO('mysql:host='.$native['host'].';port='.$native['port'].';dbname='.$database.';charset=utf8mb4', $username, $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    foreach (['companies', 'employees', 'documents', 'tasks', 'fin_payments', 'stock_movements'] as $table) {
        $expected = (int) $source->table($table)->count();
        $actual = (int) $restore->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn();
        if ($actual !== $expected) {
            throw new RuntimeException('Restored row counts differ from source; use a quiescent snapshot for comparison');
        }
        echo $table.': restored row count verified'.PHP_EOL;
    }
    foreach (['fin_payments' => 'amount', 'stock_movements' => 'quantity'] as $table => $column) {
        $expected = (string) $source->table($table)->sum($column);
        $actual = (string) ($restore->query('SELECT COALESCE(SUM(`'.$column.'`), 0) FROM `'.$table.'`')->fetchColumn());
        if (abs((float) $expected - (float) $actual) > 0.0001) {
            throw new RuntimeException('Restored business totals differ from source');
        }
        echo $table.': restored total verified'.PHP_EOL;
    }
    $restore = null;
    echo 'Isolated MySQL backup restoration: passed'.PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'Backup verification failed: '.get_class($exception).PHP_EOL);
    $failed = true;
} finally {
    if ($server !== null) {
        if ($userCreated && preg_match('/^corpms_restore_[a-f0-9]+$/', $username)) {
            $server->exec('DROP USER IF EXISTS '.$server->quote($username)."@'%'");
        }
        if (preg_match('/^corpms_restore_[a-f0-9]+_test$/', $database)) {
            $server->exec('DROP DATABASE IF EXISTS `'.$database.'`');
        }
    }
}
exit(isset($failed) ? 1 : 0);
