<?php

use App\Domains\Attendance\Services\AttendanceService;
use App\Domains\Tasks\Services\TaskService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schedule;

// п.29 ТЗ: обнаружение незакрытых рабочих дней (без clock out) — ежедневно утром за вчера
Schedule::call(function () {
    app(AttendanceService::class)->detectMissingCheckout(Carbon::yesterday());
})->dailyAt('08:30')->name('attendance.detect-missing-checkout');

// п.44 ТЗ: задачи с просроченным дедлайном → OVERDUE — ежечасно
Schedule::call(function () {
    app(TaskService::class)->markOverdue();
})->hourly()->name('tasks.mark-overdue');

// ТЗ §11 «резервное копирование»: ежедневный снимок БД с ротацией
Schedule::command('db:backup')->dailyAt('03:30')->name('db.backup')->withoutOverlapping();
Schedule::command('files:backup')->dailyAt('03:40')->name('files.backup')->withoutOverlapping();

// Инфраструктура: алерт о нездоровом приложении (БД, миграции, очередь, storage)
Schedule::command('corpms:health')->everyFifteenMinutes()->withoutOverlapping()->name('ops.health');
