<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$tables = DB::select("SHOW TABLES");
foreach ($tables as $row) { $n = array_values((array) $row)[0]; if (stripos($n, 'acc') !== false || stripos($n, 'ledger') !== false || stripos($n, 'journal') !== false) echo 'table: ' . $n . PHP_EOL; }
foreach (['accounting_entries', 'accounting_accounts', 'acc_entries', 'acc_accounts'] as $t) {
    try { echo $t . ': ' . DB::table($t)->count() . PHP_EOL; } catch (\Throwable $e) { echo $t . ': n/a' . PHP_EOL; }
}
try {
    $r = DB::table('accounting_entries')->selectRaw('MIN(entry_date) a, MAX(entry_date) b')->first();
    if ($r) { echo 'range: ' . $r->a . ' .. ' . $r->b . PHP_EOL; }
} catch (\Throwable $e) { echo 'range err: ' . $e->getMessage() . PHP_EOL; }
