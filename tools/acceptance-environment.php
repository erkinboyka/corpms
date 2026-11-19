<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$root = dirname(__DIR__).'/storage/framework/acceptance/'.bin2hex(random_bytes(6));
mkdir($root.'/sessions', 0700, true);
touch($root.'/database.sqlite');
putenv('APP_ENV=testing');
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE='.$root.'/database.sqlite');
putenv('DB_URL=');
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $root.'/database.sqlite',
    'database.connections.sqlite.url' => null, 'queue.default' => 'sync', 'mail.default' => 'array', 'cache.default' => 'array']);
DB::purge();
if (realpath(dirname(config('database.connections.sqlite.database'))) !== realpath($root)) {
    throw new RuntimeException('Acceptance database identity mismatch');
}
Artisan::call('migrate:fresh', ['--force' => true]);
Artisan::call('db:seed', ['--class' => RbacSeeder::class, '--force' => true]);
Artisan::call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);
$user = User::where('email', 'ceo@corpms.demo')->firstOrFail();
$company = $user->employee->company_id;
$now = now()->toDateTimeString();
DB::transaction(function () use ($company, $user, $now): void {
    // Exercise currency-separated summaries instead of accepting only empty money states.
    foreach (['RUB', 'USD'] as $currency) {
        $account = DB::table('fin_accounts')->insertGetId(['company_id' => $company,
            'name' => 'QA '.$currency, 'kind' => 'bank', 'currency' => $currency,
            'opening_balance' => 100, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('fin_transactions')->insert(['company_id' => $company, 'account_id' => $account,
            'direction' => 'expense', 'amount' => 25, 'source' => 'manual', 'created_by' => $user->id,
            'occurred_at' => now()->toDateString(), 'created_at' => $now, 'updated_at' => $now]);
    }
    DB::table('fin_budgets')->insert(['company_id' => $company, 'period' => now()->startOfMonth()->toDateString(),
        'direction' => 'expense', 'planned_amount' => 100, 'created_at' => $now, 'updated_at' => $now]);
    for ($batch = 0; $batch < 10; $batch++) {
        $employees = [];
        for ($index = 0; $index < 100; $index++) {
            $n = $batch * 100 + $index;
            $employees[] = ['company_id' => $company, 'employee_no' => 'LOAD-'.$n,
                'first_name' => 'Тестовый', 'last_name' => 'Сотрудник '.$n, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now];
        }
        DB::table('employees')->insert($employees);
    }
    for ($batch = 0; $batch < 100; $batch++) {
        $tasks = [];
        for ($index = 0; $index < 100; $index++) {
            $n = $batch * 100 + $index;
            $tasks[] = ['company_id' => $company, 'author_id' => $user->employee->id,
                'title' => 'Проверочная задача '.$n, 'number' => 'LOAD-'.$n, 'status' => $n % 3 === 0 ? 'completed' : 'in_progress',
                'deadline' => now()->addDays($n % 30)->toDateTimeString(), 'created_at' => $now, 'updated_at' => $now];
        }
        DB::table('tasks')->insert($tasks);
    }
});
DB::statement('PRAGMA journal_mode=WAL');
DB::disconnect();
$result = ['root' => str_replace('\\', '/', $root), 'email' => $user->email,
    'password' => 'password', 'employees' => 1022, 'tasks' => 10000];
file_put_contents($root.'/environment.json', json_encode($result, JSON_PRETTY_PRINT));
// Only the fixture location is printed; credentials remain in the ignored local fixture.
echo str_replace('\\', '/', $root).PHP_EOL;
