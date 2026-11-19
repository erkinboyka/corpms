<?php

declare(strict_types=1);

use App\Domains\Finance\Services\FinanceService;
use App\Domains\Inventory\Services\InventoryService;
use App\Models\Company;
use App\Models\FinAccount;
use App\Models\FinInvoice;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Shared\Enums\StockMovementType;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

// Creates and drops ONLY its own randomly named verification database.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (($argv[1] ?? '') === '--worker') {
    if (getenv('APP_ENV') !== 'testing' || ! preg_match('/^corpms_verify_[a-f0-9]+_test$/', (string) config('database.connections.mysql.database'))) {
        throw new RuntimeException('Worker requires an isolated verification database');
    }
    [$script, $flag, $operation, $id, $barrier, $slot] = $argv;
    $model = match ($operation) {
        'receive' => PurchaseOrder::findOrFail($id),
        'payment' => FinInvoice::findOrFail($id),
        'stock' => Warehouse::findOrFail($id),
    };
    file_put_contents($barrier.'.'.$slot, 'ready');
    $deadline = microtime(true) + 15;
    while (! is_file($barrier.'.go')) {
        clearstatcache();
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Worker barrier timed out');
        }
        usleep(10000);
    }
    try {
        match ($operation) {
            'receive' => app(InventoryService::class)->receive($model),
            'payment' => app(FinanceService::class)->registerPayment($model, ['account_id' => FinAccount::firstOrFail()->id, 'amount' => 80, 'method' => 'bank_transfer']),
            'stock' => app(InventoryService::class)->move(['company_id' => $model->company_id, 'warehouse_id' => $model->id,
                'product_id' => Product::firstOrFail()->id, 'type' => StockMovementType::Out, 'quantity' => 7]),
        };
        echo 'ok';
    } catch (LogicException) {
        echo 'rejected';
    }
    exit(0);
}

$database = 'corpms_verify_'.bin2hex(random_bytes(6)).'_test';
$server = null;
$stage = 'connect';
try {
    $native = DB::connection()->getConfig();
    if (! in_array($native['driver'], ['mysql', 'mariadb'], true)) {
        throw new RuntimeException('A MySQL/MariaDB connection is required');
    }
    $server = DB::connection()->getPdo();
    $stage = 'create-isolated-database';
    $server->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $native['database'] = $database;
    $native['url'] = null;
    unset($native['name'], $native['read'], $native['write']);
    config(['database.connections.verification' => $native, 'database.default' => 'verification', 'queue.default' => 'sync']);
    $verification = DB::connection('verification');
    if ($verification->getName() !== 'verification' || $verification->getDatabaseName() !== $database
        || $verification->selectOne('SELECT DATABASE() AS db')->db !== $database) {
        throw new RuntimeException('Verification database identity mismatch');
    }
    $stage = 'migrate-isolated-database';
    Artisan::call('migrate:fresh', ['--database' => 'verification', '--force' => true]);
    $stage = 'fixtures';
    $company = Company::create(['name' => 'Verification', 'code' => 'verify']);
    $warehouse = Warehouse::create(['company_id' => $company->id, 'name' => 'W', 'code' => 'W']);
    $product = Product::create(['company_id' => $company->id, 'name' => 'P', 'sku' => 'P']);
    $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'S']);
    $order = PurchaseOrder::create(['company_id' => $company->id, 'warehouse_id' => $warehouse->id, 'supplier_id' => $supplier->id,
        'number' => 'VERIFY', 'status' => 'ordered', 'order_date' => today()]);
    $order->items()->create(['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 1]);
    FinAccount::create(['company_id' => $company->id, 'name' => 'A', 'kind' => 'bank']);
    $invoice = FinInvoice::create(['company_id' => $company->id, 'number' => 'VERIFY', 'kind' => 'invoice', 'status' => 'issued', 'amount' => 100, 'issue_date' => today()]);
    $env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '', 'DB_DATABASE' => $database,
        'DB_HOST' => $native['host'], 'DB_PORT' => (string) $native['port'], 'DB_USERNAME' => $native['username'],
        'DB_PASSWORD' => $native['password'], 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync'];
    foreach (['receive' => $order->id, 'stock' => $warehouse->id, 'payment' => $invoice->id] as $operation => $id) {
        $stage = $operation;
        $barrier = storage_path('framework/testing/verify-'.bin2hex(random_bytes(6)));
        if (! is_dir(dirname($barrier))) {
            mkdir(dirname($barrier), 0700, true);
        }
        $workers = [];
        try {
            foreach (['a', 'b'] as $slot) {
                $worker = new Process([PHP_BINARY, __FILE__, '--worker', $operation, (string) $id, $barrier, $slot], dirname(__DIR__), $env, null, 25);
                $worker->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 15;
            while (! is_file($barrier.'.a') || ! is_file($barrier.'.b')) {
                clearstatcache();
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Concurrent workers failed to initialize');
                }
                usleep(10000);
            }
            file_put_contents($barrier.'.go', 'go');
            $results = [];
            foreach ($workers as $worker) {
                $worker->wait();
                if (! $worker->isSuccessful()) {
                    throw new RuntimeException('Concurrent worker failed');
                }
                $results[] = trim($worker->getOutput());
            }
            sort($results);
            if ($results !== ['ok', 'rejected']) {
                throw new RuntimeException('Concurrent operation was not serialized: '.$operation);
            }
            $actual = match ($operation) {
                'receive', 'stock' => app(InventoryService::class)->stock($company->id, $warehouse->id, $product->id),
                'payment' => $invoice->paidAmount(),
            };
            $expected = match ($operation) {
                'receive' => 10, 'stock' => 3, 'payment' => 80
            };
            if (abs($actual - $expected) > 0.001) {
                throw new RuntimeException('Invalid resulting balance: '.$operation);
            }
            echo $operation.': passed (one accepted, one rejected; balance='.$actual.')'.PHP_EOL;
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(1);
                }
            }
            foreach (['a', 'b', 'go'] as $suffix) {
                @unlink($barrier.'.'.$suffix);
            }
        }
    }
    $stage = 'migration-rollback-and-reapply';
    Artisan::call('migrate:rollback', ['--database' => 'verification', '--step' => 3, '--force' => true]);
    Artisan::call('migrate', ['--database' => 'verification', '--force' => true]);
    echo 'migration rollback/reapply: passed'.PHP_EOL;
} catch (Throwable $exception) {
    // Never print connection exceptions, credentials, or environment contents.
    fwrite(STDERR, 'Verification failed: '.$stage.' '.get_class($exception).' code='.$exception->getCode().PHP_EOL);
    if ($exception instanceof QueryException && $stage === 'migrate-isolated-database') {
        fwrite(STDERR, $exception->getSql().PHP_EOL);
        fwrite(STDERR, 'Database error number: '.($exception->errorInfo[1] ?? 'unknown').PHP_EOL);
    }
    $failed = true;
} finally {
    DB::disconnect('verification');
    if ($server !== null && preg_match('/^corpms_verify_[a-f0-9]+_test$/', $database)) {
        $server->exec('DROP DATABASE IF EXISTS `'.$database.'`');
    }
}
exit(isset($failed) ? 1 : 0);
