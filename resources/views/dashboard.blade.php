@extends('layouts.tabler')
@section('workspace_layout', 'overview')
@section('title', 'Дашборд')
@section('body_class', 'bm-home-page')

@push('styles')
    <link rel="stylesheet" href="/assets/css/dashboard.css?v2">
@endpush

@php
    $now = now();
    $hour = $now->hour;
    $greeting = match (true) {
        $hour < 5 => 'Доброй ночи',
        $hour < 12 => 'Доброе утро',
        $hour < 18 => 'Добрый день',
        default => 'Добрый вечер',
    };
    $userName = trim($employee?->first_name ?: auth()->user()->name);
    $permissions = app(\App\Support\PermissionService::class);
    $allowed = fn (string $permission): bool => $permissions->check(auth()->user(), $permission);

    $taskLabels = ['new' => 'Новая', 'planned' => 'Запланирована', 'in_progress' => 'В работе',
        'review' => 'На проверке', 'returned' => 'Возвращена', 'completed' => 'Завершена',
        'cancelled' => 'Отменена', 'overdue' => 'Просрочена'];
    $completedTasks = $myTasksByStatus['completed'] ?? 0;
    $taskTotal = array_sum($myTasksByStatus) - ($myTasksByStatus['cancelled'] ?? 0);
    $openTaskTotal = $taskTotal - $completedTasks;
    $taskProgress = $taskTotal > 0 ? (int) round(100 * $completedTasks / $taskTotal) : 0;

    $actions = array_values(array_filter([
        $canViewTasks ? ['clock-exclamation', 'Задачи с истёкшим сроком', $overdue, 'tasks.mine'] : null,
        $allowed('documents.view') ? ['signature', 'Документы на подпись', $waitingSignatures, 'documents.mine'] : null,
        $allowed('documents.view') && $allowed('documents.approve') ? ['file-check', 'Документы на согласование', $waitingApprovals, 'documents.index'] : null,
        $pendingLeaves !== null && $allowed('leaves.view') ? ['calendar-event', 'Заявки на отпуск', $pendingLeaves, 'schedules.leaves'] : null,
    ]));
    usort($actions, static fn ($first, $second) => ($second[2] > 0) <=> ($first[2] > 0));
    $hasPendingActions = array_sum(array_column($actions, 2)) > 0;

    $clockIn = $todayRecord?->clock_in_at;
    $clockOut = $todayRecord?->clock_out_at;
    $attendanceCount = $attendance['total'] ?? 0;
    $arrivalCount = ($attendance['present'] ?? 0) + ($attendance['late'] ?? 0) + ($attendance['remote'] ?? 0);
    $otherAttendance = max(0, $attendanceCount - $arrivalCount - ($attendance['absent_from_schedule'] ?? 0));
    $arrivalPercent = $attendanceCount > 0 ? (int) round(100 * $arrivalCount / $attendanceCount) : 0;
    $trendCounts = array_column($trend, 'count');
    $trendPeak = max([1, ...$trendCounts]);
    $hasTrend = array_sum($trendCounts) > 0;
    $heatPeak = max([1, ...array_column(array_filter(array_merge(...array_column($heatmap ?? [], 'days'))), 'count')]);
    $activeUsers = $userActivity->where('cnt', '>', 0);
@endphp

@section('content')
    <div class="bm-home">
        <div class="bm-home__lead">
            <section class="bm-home__hero" aria-labelledby="dashboard-title">
                <img class="bm-home__art" src="/assets/dashboard-relief.webp" width="1536" height="1024" alt="" fetchpriority="high">
                <header class="bm-home__hero-top">
                    <div class="bm-home__company">
                        <i class="ti ti-building" aria-hidden="true"></i>
                        <strong>{{ $employee?->company?->name ?? 'BM Systems' }}</strong>
                        @if ($employee?->department)
                            <span>{{ $employee->department->name }}</span>
                        @endif
                    </div>
                    <div class="bm-home__date">
                        <time datetime="{{ $now->toDateString() }}">{{ $now->translatedFormat('j F, D') }}</time>
                        <span class="bm-home__clock">
                            <span id="crm-clock" data-time-zone="{{ $now->getTimezone()->getName() }}">{{ $now->format('H:i') }}</span>
                            <span>{{ $now->format('T') }}</span>
                        </span>
                    </div>
                </header>

                <div class="bm-home__hero-copy">
                    <h1 id="dashboard-title">{{ $greeting }}@if ($userName !== ''),<span>{{ $userName }}</span>@endif</h1>
                    @if ($employee)
                        <div class="bm-home__shift">
                            <i class="ti ti-clock" aria-hidden="true"></i>
                            @if ($clockOut)
                                Смена завершена · @if ($clockIn){{ $clockIn->format('H:i') }}–@endif{{ $clockOut->format('H:i') }}
                            @elseif ($clockIn)
                                Приход в {{ $clockIn->format('H:i') }}
                            @else
                                Сегодня без отметки о приходе
                            @endif
                            @if ($clockIn && $todayRecord->late_minutes > 0)
                                · опоздание {{ $todayRecord->late_minutes }} мин
                            @endif
                        </div>
                    @endif
                    <nav class="bm-home__quick" aria-label="Быстрый доступ">
                        @if ($canViewTasks)
                            <a href="{{ route('tasks.mine') }}"><i class="ti ti-list-check" aria-hidden="true"></i> Мои задачи</a>
                        @endif
                        @if ($allowed('attendance.view'))
                            <a href="{{ route('schedules.mine') }}"><i class="ti ti-calendar-time" aria-hidden="true"></i> Мой график</a>
                        @endif
                        @if ($allowed('analytics.ai.use'))
                             <a href="{{ route('ai.index') }}"><i class="ti ti-sparkles" aria-hidden="true"></i> Спросить Bumi</a>
                        @endif
                    </nav>
                </div>

            </section>

            @if ($actions !== [])
                <section class="bm-home__panel bm-home__attention" aria-labelledby="dashboard-attention">
                    <header class="bm-home__attention-head">
                        <span class="bm-home__emblem {{ $hasPendingActions ? 'bm-home__emblem--pending' : '' }}">
                            <i class="ti ti-{{ $hasPendingActions ? 'bolt' : 'check' }}" aria-hidden="true"></i>
                        </span>
                        <div>
                            <h2 id="dashboard-attention">На контроле</h2>
                            <p>{{ $hasPendingActions ? 'Есть срочные действия' : 'Всё спокойно' }}</p>
                        </div>
                    </header>
                    <ul class="bm-home__queue">
                        @foreach ($actions as [$icon, $label, $count, $route])
                            <li>
                                <a href="{{ route($route) }}" class="{{ $count > 0 ? 'is-pending' : '' }}">
                                    <span class="bm-home__queue-icon"><i class="ti ti-{{ $icon }}" aria-hidden="true"></i></span>
                                    <strong>{{ $count }}</strong>
                                    <span class="bm-home__queue-label">{{ $label }}</span>
                                    <i class="ti ti-arrow-up-right" aria-hidden="true"></i>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>

        @if ($canViewTasks || $attendance !== null)
            <div class="bm-home__data {{ ! $canViewTasks || $attendance === null ? 'bm-home__data--solo' : '' }}">
                @if ($attendance !== null)
                    <section class="bm-home__panel bm-home__presence" aria-labelledby="dashboard-presence">
                        <header class="bm-home__panel-head">
                            <div>
                                <h2 id="dashboard-presence">Посещаемость сегодня</h2>
                                <p>{{ $now->translatedFormat('j F') }} · {{ number_format($attendanceCount, 0, ',', ' ') }} в сводке</p>
                            </div>
                            @if ($allowed('attendance.view'))
                                <a class="bm-home__link" href="{{ route('attendance.index') }}">Журнал <i class="ti ti-arrow-up-right" aria-hidden="true"></i></a>
                            @endif
                        </header>
                        <div class="bm-home__attendance">
                            <div class="bm-home__ring" style="--progress: {{ $arrivalPercent }}%" aria-label="{{ $arrivalCount }} с отметкой из {{ $attendanceCount }}">
                                <div><strong>{{ $arrivalPercent }}<small>%</small></strong><span>с отметкой</span></div>
                            </div>
                            <dl class="bm-home__attendance-values">
                                <div><dt><i class="is-present"></i> На месте вовремя</dt><dd>{{ $attendance['present'] }}</dd></div>
                                <div><dt><i class="is-late"></i> Опоздали</dt><dd>{{ $attendance['late'] }}</dd></div>
                                <div><dt><i class="is-remote"></i> Удалённо</dt><dd>{{ $attendance['remote'] }}</dd></div>
                                @if ($otherAttendance > 0)
                                    <div><dt><i></i> Другие записи</dt><dd>{{ $otherAttendance }}</dd></div>
                                @endif
                                <div><dt><i></i> Нет записи</dt><dd>{{ $attendance['absent_from_schedule'] }}</dd></div>
                            </dl>
                        </div>

                        @if ($trend !== [])
                            <fieldset class="bm-home__history">
                                <legend class="visually-hidden">Период посещаемости</legend>
                                <input class="visually-hidden" type="radio" name="dashboard-period" id="dashboard-week" checked>
                                <input class="visually-hidden" type="radio" name="dashboard-period" id="dashboard-quarter">
                                <div class="bm-home__history-head">
                                    <div class="bm-home__period">
                                        <label for="dashboard-week">7 дней</label>
                                        <label for="dashboard-quarter">12 недель</label>
                                    </div>
                                    @if ($allowed('reports.view'))
                                        <a class="bm-home__link" href="{{ route('reports.attendance') }}">Отчёт <i class="ti ti-arrow-up-right" aria-hidden="true"></i></a>
                                    @endif
                                </div>
                                <ul class="bm-home__bars {{ $hasTrend ? '' : 'bm-home__bars--empty' }}" aria-label="Число записей посещаемости по дням">
                                    @foreach ($trend as $day)
                                        <li title="{{ \Carbon\Carbon::parse($day['date'])->format('d.m') }} · {{ $day['count'] }}">
                                            <span>{{ $day['count'] }}</span>
                                            <div><i style="--height: {{ round(100 * $day['count'] / $trendPeak, 2) }}%"></i></div>
                                            <time datetime="{{ $day['date'] }}">{{ $day['label'] }}</time>
                                        </li>
                                    @endforeach
                                </ul>
                                <div class="bm-home__heatmap" aria-label="Тепловая карта посещаемости">
                                    <div class="bm-home__heat" role="img" aria-label="{{ $heatmapTotal }} записей за 12 недель">
                                        @foreach ($heatmap as $week)
                                            <div>
                                                <span title="{{ $week['label'] }}">{{ mb_substr($week['label'], 0, 3) }}</span>
                                                @foreach ($week['days'] as $cell)
                                                    @if ($cell === null)
                                                        <i class="is-future"></i>
                                                    @else
                                                        <i style="--heat: {{ ceil(4 * $cell['count'] / $heatPeak) * 25 }}%"
                                                           title="{{ \Carbon\Carbon::parse($cell['date'])->format('d.m') }} · {{ $cell['count'] }}"></i>
                                                    @endif
                                                @endforeach
                                            </div>
                                        @endforeach
                                    </div>
                                    <p>Записей за период — {{ $heatmapTotal }} · {{ \Carbon\Carbon::parse($heatmapFrom)->format('d.m') }}–{{ \Carbon\Carbon::parse($heatmapTo)->format('d.m') }}</p>
                                </div>
                                @if (! $hasTrend)
                                    <p class="bm-home__trend-empty">За неделю записей пока нет</p>
                                @endif
                            </fieldset>
                        @endif
                    </section>
                @endif
                @if ($canViewTasks)
                    <section class="bm-home__panel bm-home__work" aria-labelledby="dashboard-work">
                        <header class="bm-home__panel-head">
                            <div>
                                <h2 id="dashboard-work">Моя работа</h2>
                                <p>Лично назначенные задачи</p>
                            </div>
                            <a class="bm-home__link" href="{{ route('tasks.mine') }}">Открыть список <i class="ti ti-arrow-up-right" aria-hidden="true"></i></a>
                        </header>
                        <div class="bm-home__progress">
                            <div>
                                <strong>{{ $openTaskTotal }}</strong>
                                <span>открыто</span>
                            </div>
                            <div class="bm-home__progress-detail">
                                <p>Завершено <strong>{{ $completedTasks }} из {{ $taskTotal }}</strong></p>
                                <div class="bm-home__progress-track" role="progressbar" aria-label="Завершённые задачи"
                                     aria-valuenow="{{ $completedTasks }}" aria-valuemax="{{ max(1, $taskTotal) }}">
                                    <span style="--progress: {{ $taskProgress }}%"></span>
                                </div>
                            </div>
                        </div>
                        <ul class="bm-home__tasks">
                            @foreach ($upcomingTasks as $task)
                                @php
                                    $late = $task->status->value === 'overdue' || $task->deadline?->lt($now);
                                    $priority = match ($task->priority->value) {
                                        'critical' => 'критический приоритет',
                                        'high' => 'высокий приоритет',
                                        default => null,
                                    };
                                @endphp
                                <li>
                                    <a href="{{ route('tasks.show', $task) }}">
                                        <i class="ti ti-{{ $late ? 'alert-circle' : 'circle-dashed' }} {{ $late ? 'is-late' : '' }}" aria-hidden="true"></i>
                                        <span class="bm-home__task-text">
                                            <strong>{{ $task->title }}</strong>
                                            <small>{{ $late ? 'Срок истёк' : $taskLabels[$task->status->value] }}@if ($priority) · {{ $priority }}@endif</small>
                                        </span>
                                        @if ($task->deadline)
                                            <time datetime="{{ $task->deadline->toAtomString() }}">{{ $task->deadline->translatedFormat('j M') }}</time>
                                        @endif
                                        <i class="ti ti-chevron-right" aria-hidden="true"></i>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                        @if ($upcomingTasks->isEmpty())
                            <div class="bm-home__empty">
                                <span class="bm-home__emblem"><i class="ti ti-clipboard-check" aria-hidden="true"></i></span>
                                <div><strong>Открытых задач нет</strong><p>Новые назначения появятся здесь.</p></div>
                            </div>
                        @endif
                    </section>
                @endif

            </div>
        @endif

        @if ($userActivity->isNotEmpty() || $biometrics !== null)
            <div class="bm-home__data bm-home__system {{ $userActivity->isEmpty() || $biometrics === null ? 'bm-home__data--solo' : '' }}">
                @if ($userActivity->isNotEmpty())
                    <section class="bm-home__panel" aria-labelledby="dashboard-activity">
                        <header class="bm-home__panel-head">
                            <div><h2 id="dashboard-activity">Активность пользователей</h2><p>{{ $activityTotal }} событий за 12 недель · {{ $activeUsers->count() }} активных</p></div>
                            @if ($allowed('audit.view@company'))
                                <a class="bm-home__link" href="{{ route('audit.index') }}">Журнал <i class="ti ti-arrow-up-right" aria-hidden="true"></i></a>
                            @endif
                        </header>
                        @if ($activeUsers->isNotEmpty())
                            <ol class="bm-home__people">
                                @foreach ($activeUsers->take(4) as $person)
                                    <li>
                                        <span class="bm-home__avatar" aria-hidden="true">{{ \App\Support\AvatarInitials::of($person['name']) }}</span>
                                        <span>{{ $person['name'] }}</span>
                                        <strong>{{ $person['cnt'] }}</strong>
                                    </li>
                                @endforeach
                            </ol>
                        @else
                            <p class="bm-home__muted">За этот период событий пока нет.</p>
                        @endif
                    </section>
                @endif

                @if ($biometrics !== null)
                    <section class="bm-home__panel" aria-labelledby="dashboard-devices">
                        <header class="bm-home__panel-head">
                            <div><h2 id="dashboard-devices">Биометрия и устройства</h2></div>
                            @if ($allowed('biometry.devices.view'))
                                <a class="bm-home__link" href="{{ route('biometrics.index') }}">Устройства <i class="ti ti-arrow-up-right" aria-hidden="true"></i></a>
                            @endif
                        </header>
                        <dl class="bm-home__devices">
                            <div><dt><i class="ti ti-wifi" aria-hidden="true"></i> Онлайн</dt><dd>{{ $biometrics['devices_online'] }} из {{ $biometrics['devices_total'] }}</dd></div>
                            <div><dt><i class="ti ti-fingerprint" aria-hidden="true"></i> Событий сегодня</dt><dd>{{ $biometrics['events_today'] }}</dd></div>
                            <div><dt><i class="ti ti-user-check" aria-hidden="true"></i> Профилей</dt><dd>{{ $biometrics['profiles'] }}</dd></div>
                        </dl>
                    </section>
                @endif
            </div>
        @endif
    </div>
@endsection
