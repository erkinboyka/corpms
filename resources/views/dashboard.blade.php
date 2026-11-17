@extends('layouts.tabler')

@section('title', 'Дашборд')

@php
    $greetingHours = (int) now()->format('G');
    $greeting = $greetingHours < 5 ? 'Доброй ночи' : ($greetingHours < 12 ? 'Доброе утро' : ($greetingHours < 18 ? 'Добрый день' : 'Добрый вечер'));
    $userName = trim($employee?->user->name ?? auth()->user()->name ?? '');
    $tasksTotal = array_sum($myTasksByStatus);
    $tasksMax = max(1, max(array_merge([1], array_values($myTasksByStatus))));
    $tasksDone = (int) ($myTasksByStatus['completed'] ?? 0);
    $tasksProgress = $tasksTotal > 0 ? (int) round($tasksDone / $tasksTotal * 100) : 0;
    $attTotal = $attendance !== null ? max(1, (int) ($attendance['present'] + $attendance['late'] + $attendance['remote'] + $attendance['absent_from_schedule'])) : 1;
    $attPct = $attendance !== null ? [
        'present' => (int) round($attendance['present'] / $attTotal * 100),
        'late' => (int) round($attendance['late'] / $attTotal * 100),
        'remote' => (int) round($attendance['remote'] / $attTotal * 100),
        'absent' => (int) round($attendance['absent_from_schedule'] / $attTotal * 100),
    ] : [];
    $trendMax = $trend !== [] ? max(1, max(array_column($trend, 'count'))) : 1;
    $trendPeak = $trend !== [] ? max(array_column($trend, 'count')) : 0;
    $heatMax = 1;
    if ($heatmap !== null) {
        foreach ($heatmap as $week) {
            foreach ($week['days'] as $cell) {
                if ($cell !== null && $cell['count'] > $heatMax) {
                    $heatMax = $cell['count'];
                }
            }
        }
    }

    /** Русская форма: 1 отметка / 2 отметки / 5 отметок */
    $heatWord = static function (int $n): string {
        $d10 = $n % 10;
        $d100 = $n % 100;
        if ($d10 === 1 && $d100 !== 11) {
            return 'отметка';
        }
        if ($d10 >= 2 && $d10 <= 4 && ($d100 < 12 || $d100 > 14)) {
            return 'отметки';
        }
        return 'отметок';
    };

    $activityMax = max(1, (int) $userActivity->max('cnt'));
@endphp

@section('content')
    <div class="page-header d-print-none mb-4">
        <div class="row g-2 align-items-center">
            <div class="col">
                <div class="crm-eyebrow">Обзор</div>
                <h2 class="page-title mt-1">{{ $greeting }}@if ($userName !== ''), {{ $userName }}@endif</h2>
                <div class="text-secondary mt-1">
                    @if ($employee !== null)
                        {{ $employee->position->name ?? 'Сотрудник' }} ·
                        {{ $employee->company->name ?? 'CorpMS' }}@if ($employee->department)
                            / {{ $employee->department->name }}
                        @endif
                    @else
                        CorpMS — корпоративная система управления
                    @endif
                </div>
            </div>
            <div class="col-auto">
                <div class="crm-chip crm-chip--cyan">
                    <i class="ti ti-calendar me-1"></i>{{ \Carbon\Carbon::now()->translatedFormat('d F Y') }}
                    <span class="fw-bold ms-1" id="crm-clock">{{ \Carbon\Carbon::now()->format('H:i') }}</span>
                </div>
            </div>
        </div>
    </div>

    <div class="row row-cards g-3">
        {{-- ============ KPI ============ --}}
        <div class="col-6 col-xl-3">
            <div class="card crm-kpi">
                <div class="card-body p-3">
                    <div class="crm-kpi__head mb-3">
                        <span class="crm-kpi__icon"><i class="ti ti-clock-hour-4"></i></span>
                        <span class="crm-kpi__label">Мой статус сегодня</span>
                    </div>
                    @if ($todayRecord !== null)
                        <div class="crm-kpi__value">
                            {{ $todayRecord->clock_in_at?->format('H:i') ?? '—' }}
                            <span class="text-secondary">—</span>
                            {{ $todayRecord->clock_out_at?->format('H:i') ?? '—' }}
                        </div>
                        <div class="crm-kpi__meta mt-2">
                            @if ($todayRecord->late_minutes > 0)
                                <span class="crm-chip crm-chip--orange">опоздание {{ $todayRecord->late_minutes }} мин</span>
                            @else
                                <span class="crm-chip crm-chip--green">на месте</span>
                            @endif
                        </div>
                    @else
                        <div class="crm-kpi__value">—</div>
                        <div class="crm-kpi__meta mt-2"><span class="crm-chip crm-chip--grey">нет отметки</span></div>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-6 col-xl-3">
            <div class="card crm-kpi">
                <div class="card-body p-3">
                    <div class="crm-kpi__head mb-3">
                        <span class="crm-kpi__icon"><i class="ti ti-list-check"></i></span>
                        <span class="crm-kpi__label">Мои задачи</span>
                    </div>
                    <div class="crm-kpi__value" data-count="{{ $tasksTotal }}">{{ $tasksTotal }}</div>
                    <div class="crm-kpi__meta mt-2 d-flex align-items-center gap-2 flex-wrap">
                        @if ($overdue > 0)
                            <a href="{{ route('tasks.overdue') }}" class="crm-chip crm-chip--red text-decoration-none">просрочено: {{ $overdue }}</a>
                        @else
                            <span class="crm-chip crm-chip--green">в графике</span>
                        @endif
                        <a href="{{ route('tasks.mine') }}" class="crm-kpi__meta text-secondary text-decoration-none">к списку →</a>
                    </div>
                    <div class="crm-kpi__bar mt-3" title="Завершено {{ $tasksDone }} из {{ $tasksTotal }}">
                        <i style="--w: {{ $tasksProgress }}%"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-xl-3">
            <div class="card crm-kpi">
                <div class="card-body p-3">
                    <div class="crm-kpi__head mb-3">
                        <span class="crm-kpi__icon"><i class="ti ti-signature"></i></span>
                        <span class="crm-kpi__label">Ждёт моей подписи</span>
                    </div>
                    <div class="crm-kpi__value {{ $waitingSignatures > 0 ? '' : 'text-secondary' }}" data-count="{{ $waitingSignatures }}">{{ $waitingSignatures }}</div>
                    <div class="crm-kpi__meta mt-2">
                        @if ($waitingSignatures > 0)
                            <a href="{{ route('documents.index') }}" class="crm-chip crm-chip--orange text-decoration-none">подписать</a>
                        @else
                            <span class="crm-chip crm-chip--green">всё подписано</span>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-xl-3">
            <div class="card crm-kpi">
                <div class="card-body p-3">
                    <div class="crm-kpi__head mb-3">
                        <span class="crm-kpi__icon"><i class="ti ti-calendar-off"></i></span>
                        <span class="crm-kpi__label">{{ $pendingLeaves !== null ? 'Отпуска на согласовании' : 'Согласование документов' }}</span>
                    </div>
                    @if ($pendingLeaves !== null)
                        <div class="crm-kpi__value {{ $pendingLeaves > 0 ? '' : 'text-secondary' }}" data-count="{{ $pendingLeaves }}">{{ $pendingLeaves }}</div>
                        <div class="crm-kpi__meta mt-2">
                            @if ($pendingLeaves > 0)
                                <a href="{{ route('schedules.leaves') }}" class="crm-chip crm-chip--orange text-decoration-none">к заявкам</a>
                            @else
                                <span class="crm-chip crm-chip--green">нет заявок</span>
                            @endif
                        </div>
                    @else
                        <div class="crm-kpi__value {{ $waitingApprovals > 0 ? '' : 'text-secondary' }}" data-count="{{ $waitingApprovals }}">{{ $waitingApprovals }}</div>
                        <div class="crm-kpi__meta mt-2">
                            @if ($waitingApprovals > 0)
                                <a href="{{ route('documents.index') }}" class="crm-chip crm-chip--orange text-decoration-none">к документам</a>
                            @else
                                <span class="crm-chip crm-chip--green">ничего не ждёт</span>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- ============ Посещаемость + мониторинг задач ============ --}}
        @if ($attendance !== null && ($attendance['total'] > 0 || $attendance['absent_from_schedule'] > 0))
            <div class="col-12 col-xl-7">
                <div class="card crm-panel h-100">
                    <div class="card-header">
                        <div>
                            <div class="card-title">Посещаемость сегодня (в моём scope)</div>
                            <div class="crm-panel__sub">отметки {{ \Carbon\Carbon::now()->translatedFormat('d F') }} · всего {{ (int) $attendance['present'] + (int) $attendance['late'] + (int) $attendance['remote'] + (int) $attendance['absent_from_schedule'] }} человек</div>
                        </div>
                        <div class="ms-auto"><a class="btn btn-sm btn-ghost-primary" href="{{ route('attendance.index') }}">Открыть<i class="ti ti-arrow-right ms-1"></i></a></div>
                    </div>
                    <div class="card-body">
                        <div class="row g-3 mb-3">
                            <div class="col-6 col-md-3">
                                <div class="crm-stat">
                                    <span class="crm-stat__dot" style="--c:#2fb344"></span>
                                    <div><div class="crm-stat__val">{{ $attendance['present'] }}</div><div class="crm-stat__label">на месте</div></div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="crm-stat">
                                    <span class="crm-stat__dot" style="--c:#f76707"></span>
                                    <div><div class="crm-stat__val">{{ $attendance['late'] }}</div><div class="crm-stat__label">опоздали</div></div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="crm-stat">
                                    <span class="crm-stat__dot" style="--c:#4299e1"></span>
                                    <div><div class="crm-stat__val">{{ $attendance['remote'] }}</div><div class="crm-stat__label">удалённо</div></div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="crm-stat">
                                    <span class="crm-stat__dot" style="--c:#d63939"></span>
                                    <div><div class="crm-stat__val">{{ $attendance['absent_from_schedule'] }}</div><div class="crm-stat__label">нет отметки</div></div>
                                </div>
                            </div>
                        </div>
                        <div class="crm-stack" role="img" aria-label="Распределение посещаемости">
                            <span class="crm-stack__seg" style="--w: {{ $attPct['present'] }}%; --c:#2fb344" title="на месте {{ $attPct['present'] }}%"></span>
                            <span class="crm-stack__seg" style="--w: {{ $attPct['late'] }}%; --c:#f76707" title="опоздали {{ $attPct['late'] }}%"></span>
                            <span class="crm-stack__seg" style="--w: {{ $attPct['remote'] }}%; --c:#4299e1" title="удалённо {{ $attPct['remote'] }}%"></span>
                            <span class="crm-stack__seg" style="--w: {{ $attPct['absent'] }}%; --c:#d63939" title="нет отметки {{ $attPct['absent'] }}%"></span>
                        </div>
                        <div class="crm-legend mt-2">
                            <span><i style="--c:#2fb344"></i>на месте · {{ $attPct['present'] }}%</span>
                            <span><i style="--c:#f76707"></i>опоздали · {{ $attPct['late'] }}%</span>
                            <span><i style="--c:#4299e1"></i>удалённо · {{ $attPct['remote'] }}%</span>
                            <span><i style="--c:#d63939"></i>нет отметки · {{ $attPct['absent'] }}%</span>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        <div class="col-12 {{ $attendance !== null && ($attendance['total'] > 0 || $attendance['absent_from_schedule'] > 0) ? 'col-xl-5' : 'col-xl-6' }}">
            <div class="card crm-panel h-100">
                <div class="card-header">
                    <div>
                        <div class="card-title">Мониторинг задач</div>
                        <div class="crm-panel__sub">{{ $tasksTotal > 0 ? 'всего ' . $tasksTotal . ' · завершено ' . $tasksDone : 'по статусам' }}</div>
                    </div>
                    <div class="ms-auto"><a class="btn btn-sm btn-ghost-primary" href="{{ route('tasks.index') }}">Открыть<i class="ti ti-arrow-right ms-1"></i></a></div>
                </div>
                <div class="card-body">
                    @if ($tasksTotal === 0)
                        <div class="text-secondary py-3 text-center">
                            <i class="ti ti-clipboard-check d-block mb-2" style="font-size:28px"></i>
                            Задач нет — перейти в раздел <a href="{{ route('tasks.index') }}">Все задачи</a>.
                        </div>
                    @else
                        @foreach (['new' => ['Новые', '#6c7a91'], 'planned' => ['Запланированные', '#4299e1'], 'in_progress' => ['В работе', '#1f9cf0'], 'review' => ['На проверке', '#f59f00'], 'returned' => ['Возвращённые', '#f76707'], 'completed' => ['Завершённые', '#2fb344'], 'cancelled' => ['Отменённые', '#868e96'], 'overdue' => ['Просроченные', '#d63939']] as $key => [$label, $color])
                            @if (($myTasksByStatus[$key] ?? 0) > 0)
                                <div class="d-flex align-items-center gap-3 mb-2">
                                    <span class="crm-chip" style="background: color-mix(in srgb, {{ $color }} 14%, transparent); color: {{ $color }}">{{ $label }}</span>
                                    <div class="crm-kpi__bar flex-grow-1" style="--w: 100%; background: transparent">
                                        <i style="--w: {{ (int) round($myTasksByStatus[$key] / $tasksMax * 100) }}%; background: {{ $color }}"></i>
                                    </div>
                                    <strong class="crm-table-num" style="min-width: 2ch; text-align: right">{{ $myTasksByStatus[$key] }}</strong>
                                </div>
                            @endif
                        @endforeach
                    @endif
                </div>
            </div>
        </div>

        {{-- ============ Динамика и тепловая карта ============ --}}
        @if ($trend !== [])
            <div class="col-12 col-xl-7">
                <div class="card crm-panel h-100">
                    <div class="card-header">
                        <div>
                            <div class="card-title">Динамика за 7 дней</div>
                            <div class="crm-panel__sub">отметки посещаемости по дням · пик {{ $trendPeak }}</div>
                        </div>
                        <div class="ms-auto"><a class="btn btn-sm btn-ghost-primary" href="{{ route('reports.attendance') }}">Отчёт<i class="ti ti-arrow-right ms-1"></i></a></div>
                    </div>
                    <div class="card-body">
                        <div class="crm-bars">
                            @foreach ($trend as $day)
                                <div class="crm-bar">
                                    <div class="crm-bar__col" style="--h: {{ max(4, (int) round($day['count'] / $trendMax * 100)) }}%; animation-delay: {{ $loop->index * 70 }}ms"
                                         data-tip="{{ \Carbon\Carbon::parse($day['date'])->format('d.m') }} · {{ $day['count'] }}"></div>
                                    <span class="crm-bar__label">{{ $day['label'] }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-xl-5">
                <div class="card crm-panel h-100">
                    <div class="card-header">
                        <div>
                            <div class="card-title">Тепловая карта</div>
                            <div class="crm-panel__sub">
                                {{ $heatmapTotal }} {{ $heatWord($heatmapTotal) }} за 12 недель ·
                                {{ \Carbon\Carbon::parse($heatmapFrom)->format('d.m.Y') }} — {{ \Carbon\Carbon::parse($heatmapTo)->format('d.m.Y') }}
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="crm-heat-wrap">
                            <div class="crm-heat-months">
                                @foreach ($heatmap as $week)
                                    <span>{{ $week['label'] }}</span>
                                @endforeach
                            </div>
                            <div class="crm-heat-body">
                                <div class="crm-heat-days">
                                    <span>Пн</span>
                                    <span></span>
                                    <span>Ср</span>
                                    <span></span>
                                    <span>Пт</span>
                                    <span></span>
                                    <span></span>
                                </div>
                                <div class="crm-heat">
                                    @foreach ($heatmap as $week)
                                        @foreach ($week['days'] as $cell)
                                            @if ($cell === null)
                                                <span class="crm-heat__c is-empty"></span>
                                            @else
                                                @php
                                                    $level = $cell['count'] === 0 ? 0 : (int) max(1, min(4, ceil($cell['count'] / $heatMax * 4)));
                                                    $cellDate = \Carbon\Carbon::parse($cell['date']);
                                                @endphp
                                                <span class="crm-heat__c {{ $level > 0 ? 'l' . $level : '' }}"
                                                      title="{{ $cellDate->translatedFormat('d F Y') }} · {{ $cell['count'] === 0 ? 'нет отметок' : $cell['count'] . ' ' . $heatWord($cell['count']) }}"></span>
                                            @endif
                                        @endforeach
                                    @endforeach
                                </div>
                            </div>
                        </div>
                        <div class="crm-heat-scale mt-3">
                            <span>Меньше</span>
                            <i class="crm-heat__c"></i>
                            <i class="crm-heat__c l1"></i>
                            <i class="crm-heat__c l2"></i>
                            <i class="crm-heat__c l3"></i>
                            <i class="crm-heat__c l4"></i>
                            <span>Больше</span>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        {{-- ============ Активность всех пользователей (журнал аудита) ============ --}}
        @if ($userActivity->isNotEmpty())
            <div class="col-12">
                <div class="card crm-panel">
                    <div class="card-header">
                        <div>
                            <div class="card-title">Активность пользователей</div>
                            <div class="crm-panel__sub">
                                за 12 недель · {{ $activityTotal }} событий ·
                                {{ $userActivity->where('cnt', '>', 0)->count() }} из {{ $userActivity->count() }} активны
                            </div>
                        </div>
                        <div class="ms-auto"><a class="btn btn-sm btn-ghost-primary" href="{{ route('audit.index') }}">Журнал действий<i class="ti ti-arrow-right ms-1"></i></a></div>
                    </div>
                    <div class="card-body">
                        @php
                            /* Круговая диаграмма активности: доля каждого пользователя
                               от числа событий за 12 недель. Топ-6 + «прочие». */
                            $actPalette = ['#0b9b72', '#4f74e3', '#f76707', '#7c5ce0', '#d6455f', '#c98a12'];
                            $actActive = $userActivity->where('cnt', '>', 0)->values();
                            $actTop = $actActive->take(6)->values();
                            $actRest = $actActive->slice(6);
                            $actRestSum = (int) $actRest->sum('cnt');
                            $actTotal = max(1, (int) $activityTotal);

                            $actSlices = [];
                            foreach ($actTop as $i => $person) {
                                $actSlices[] = [
                                    'label' => $person['name'],
                                    'cnt' => (int) $person['cnt'],
                                    'color' => $actPalette[$i % count($actPalette)],
                                ];
                            }
                            if ($actRestSum > 0) {
                                $actSlices[] = ['label' => 'Прочие', 'cnt' => $actRestSum, 'color' => '#97a3b6'];
                            }

                            /* conic-gradient: последовательные дуги по долям */
                            $actParts = [];
                            $actOffset = 0;
                            foreach ($actSlices as $slice) {
                                $pct = $slice['cnt'] / $actTotal * 100;
                                $actParts[] = sprintf('%s %.4f%% %.4f%%', $slice['color'], $actOffset, $actOffset + $pct);
                                $actOffset += $pct;
                            }
                            $actGradient = $actParts === [] ? '#e6e9ef 0 100%' : implode(', ', $actParts);
                            $actCenterPct = $actSlices === [] ? 0 : (int) round($actSlices[0]['cnt'] / $actTotal * 100);
                        @endphp

                        <div class="crm-donut">
                            <div class="crm-donut__chart" role="img"
                                 aria-label="Распределение активности пользователей"
                                 style="background: conic-gradient({{ $actGradient }});">
                                <div class="crm-donut__hole">
                                    <strong class="crm-donut__value">{{ number_format($activityTotal, 0, ',', ' ') }}</strong>
                                    <span class="crm-donut__label">событий</span>
                                </div>
                            </div>

                            <ul class="crm-donut__legend">
                                @forelse ($actSlices as $slice)
                                    <li>
                                        <i style="--c: {{ $slice['color'] }}"></i>
                                        <span class="crm-donut__name" title="{{ $slice['label'] }}">{{ $slice['label'] }}</span>
                                        <strong class="crm-table-num">{{ $slice['cnt'] }}</strong>
                                        <span class="crm-donut__pct">{{ (int) round($slice['cnt'] / $actTotal * 100) }}%</span>
                                    </li>
                                @empty
                                    <li class="text-secondary">Событий пока нет — журнал наполняется по мере работы.</li>
                                @endforelse
                            </ul>
                        </div>

                        @if ($userActivity->isNotEmpty())
                            <div class="crm-act-grid mt-3">
                                @foreach ($userActivity->take(3) as $person)
                                    @php
                                        $words = preg_split('/\s+/u', trim((string) $person['name']), -1, PREG_SPLIT_NO_EMPTY);
                                        $initials = '';
                                        foreach (array_slice($words, 0, 2) as $w) {
                                            $initials .= mb_strtoupper(mb_substr($w, 0, 1));
                                        }
                                    @endphp
                                    <div class="crm-act-row {{ $person['cnt'] > 0 ? '' : 'is-idle' }}">
                                        <span class="crm-act-avatar">{{ $initials !== '' ? $initials : 'U' }}</span>
                                        <div class="crm-act-main">
                                            <div class="crm-act-name">{{ $person['name'] }}</div>
                                            <div class="crm-act-sub">
                                                @if ($person['last_at'] !== null)
                                                    {{ \Carbon\Carbon::parse($person['last_at'])->translatedFormat('d F, H:i') }}
                                                @else
                                                    нет событий
                                                @endif
                                            </div>
                                        </div>
                                        <div class="crm-act-bar" title="{{ $person['cnt'] }} событий">
                                            <i style="--w: {{ (int) round($person['cnt'] / $activityMax * 100) }}%"></i>
                                        </div>
                                        <strong class="crm-table-num crm-act-cnt">{{ $person['cnt'] }}</strong>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @endif

        {{-- ============ Биометрия ============ --}}
        @if ($biometrics !== null)
            <div class="col-12">
                <div class="card crm-panel">
                    <div class="card-header">
                        <div class="card-title">Биометрия и устройства</div>
                        <div class="ms-auto"><a class="btn btn-sm btn-ghost-primary" href="{{ route('biometrics.index') }}">Все устройства<i class="ti ti-arrow-right ms-1"></i></a></div>
                    </div>
                    <div class="card-body">
                        <div class="d-flex gap-3 flex-wrap align-items-center">
                            <span class="crm-chip crm-chip--green"><i class="ti ti-shield-check me-1"></i>{{ $biometrics['devices_online'] }}/{{ $biometrics['devices_total'] }} устройств онлайн</span>
                            <span class="crm-chip"><i class="ti ti-user-check me-1"></i>{{ $biometrics['profiles'] }} профилей</span>
                            <span class="crm-chip crm-chip--cyan"><i class="ti ti-fingerprint me-1"></i>{{ $biometrics['events_today'] }} событий сегодня</span>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>
@endsection
