<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require __DIR__.'/../vendor/autoload.php';
putenv('APP_ENV=testing');
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=:memory:');
putenv('DB_URL=');
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.url' => null,
    'cache.default' => 'array', 'session.driver' => 'array', 'queue.default' => 'sync', 'mail.default' => 'array',
    'corpms.ai.base_url' => '', 'corpms.ai.api_key' => '']);
DB::purge();
if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') {
    throw new RuntimeException('Only an isolated in-memory database is supported');
}
Http::preventStrayRequests();
Artisan::call('migrate:fresh', ['--force' => true]);
Artisan::call('db:seed', ['--class' => RbacSeeder::class, '--force' => true]);
Artisan::call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);
$user = User::where('email', 'ceo@corpms.demo')->firstOrFail();
$kernel = app(Kernel::class);
$report = ['environment' => 'local PHP HTTP kernel, SQLite :memory:, demo seed, no browser/network/concurrent load',
    'employees' => Employee::count(), 'tasks' => Task::count(), 'samples_per_route' => 5, 'routes' => []];
foreach (['/dashboard', '/tasks', '/employees', '/finance/accounts', '/payroll'] as $path) {
    $samples = [];
    $counts = [];
    for ($index = 0; $index < 5; $index++) {
        Auth::setUser($user);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $request = Request::create($path, 'GET');
        $start = hrtime(true);
        $response = $kernel->handle($request);
        $samples[] = round((hrtime(true) - $start) / 1e6, 2);
        $counts[] = count(DB::getQueryLog());
        DB::disableQueryLog();
        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException('Unexpected HTTP status for '.$path.': '.$response->getStatusCode());
        }
        $kernel->terminate($request, $response);
    }
    sort($samples);
    $report['routes'][$path] = ['median_ms' => $samples[2], 'max_ms' => max($samples), 'max_queries' => max($counts)];
}
$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
file_put_contents(storage_path('logs/hardening-performance.json'), $json);
echo $json.PHP_EOL;
