<?php

declare(strict_types=1);
use App\Models\User;
use App\Support\PermissionService;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$ceo = User::query()->where('email', 'ceo@corpms.demo')->first();
echo 'ceo user: '.($ceo->id ?? 'NULL').PHP_EOL;

$service = new PermissionService;

var_dump($service->can($ceo, 'tasks.view'));
var_dump($service->check($ceo, 'settings.view'));

$emp = User::query()->where('email', 'it-emp1@corpms.demo')->first();
var_dump($service->can($emp, 'tasks.view'));
var_dump($service->can($emp, 'settings.view'));
