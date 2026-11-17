<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$failures = 0;

foreach (glob(__DIR__ . '/../app/Models/*.php') as $file) {
    $class = 'App\\Models\\' . basename($file, '.php');
    try {
        $model = new $class();
        echo 'OK ' . $class . ' -> ' . $model->getTable() . PHP_EOL;
    } catch (Throwable $e) {
        $failures++;
        echo 'FAIL ' . $class . ': ' . $e->getMessage() . PHP_EOL;
    }
}

echo PHP_EOL . 'Models total_query: ';
echo App\Models\Employee::query()->count() . ' employees' . PHP_EOL;

echo $failures === 0 ? 'SMOKE OK' . PHP_EOL : 'SMOKE WITH ERRORS' . PHP_EOL;
