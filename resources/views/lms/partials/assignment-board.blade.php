@php
    $columns = [
        'draft' => ['title' => __('Черновик'), 'icon' => 'notes', 'chip' => 'cyan'],
        'submitted' => ['title' => __('На проверке'), 'icon' => 'send', 'chip' => 'orange'],
        'reviewed' => ['title' => __('Проверено'), 'icon' => 'circle-check', 'chip' => 'green'],
        'returned' => ['title' => __('На доработке'), 'icon' => 'rotate-clockwise', 'chip' => 'red'],
    ];
@endphp
<div class="crm-kanban lms-assignment-kanban">
    @foreach ($columns as $key => $column)
        <section class="crm-kanban__col" data-status="{{ $key }}">
            <header class="crm-kanban__head">
                <span class="crm-kanban__title"><i class="ti ti-{{ $column['icon'] }}" aria-hidden="true"></i>{{ $column['title'] }}</span>
                <span class="crm-kanban__count">{{ count($board[$key] ?? []) }}</span>
            </header>
            <div class="lms-assignment-kanban__list">
                @forelse ($board[$key] ?? [] as $card)
                    @php $assignment = $card['assignment']; $submission = $card['submission']; @endphp
                    <article class="crm-kanban__card lms-assignment-card @if($assignment->photo_path) has-photo @endif">
                        @if ($assignment->photo_path)
                            @include('partials.entity-photo-display', [
                                'photoPath' => $assignment->photo_path,
                                'photoClass' => 'k-thumb',
                                'photoAlt' => $assignment->title,
                            ])
                        @endif
                        @if ($assignment->course)
                            <a class="k-title text-reset text-decoration-none" href="{{ route('lms.courses.show', $assignment->course) }}">{{ $assignment->title }}</a>
                        @else
                            <span class="k-title">{{ $assignment->title }}</span>
                        @endif
                        <div class="k-meta">
                            <span class="crm-chip crm-chip--{{ $column['chip'] }}">{{ $column['title'] }}</span>
                            <span @if($assignment->due_at && $assignment->due_at->isPast() && $key !== 'reviewed') class="text-danger fw-semibold" @else class="text-secondary" @endif>
                                <i class="ti ti-calendar-event me-1"></i>{{ $assignment->due_at?->format('d.m') ?? '—' }}
                            </span>
                        </div>
                        @if (filled($submission?->body))
                            <div class="k-note"><i class="ti ti-notes me-1"></i>{{ \Illuminate\Support\Str::limit(trim(html_entity_decode(strip_tags($submission->body), ENT_QUOTES, 'UTF-8')), 110) }}</div>
                        @endif
                        @if ($submission?->employee || $submission?->status?->value === 'reviewed')
                            <div class="k-foot">
                                @if ($submission?->employee)
                                    <span class="k-avatar">{{ mb_substr($submission->employee->first_name, 0, 1) }}{{ mb_substr($submission->employee->last_name, 0, 1) }}</span>
                                    <span class="text-truncate">{{ trim($submission->employee->last_name.' '.$submission->employee->first_name) }}</span>
                                @endif
                                @if ($submission?->status?->value === 'reviewed')
                                    <span class="crm-chip crm-chip--green ms-auto"><i class="ti ti-star me-1"></i>{{ $submission->score ?? '—' }}/{{ $assignment->max_score }}</span>
                                @endif
                            </div>
                        @endif

                        @if (! $submission || in_array($submission->status?->value, ['submitted', 'returned', 'reviewed'], true))
                            <div class="k-actions">
                                @if (! $submission)
                                    <button type="button" class="btn btn-sm btn-primary k-action"
                                            data-lms-submit="{{ $assignment->id }}"
                                            data-lms-title="{{ $assignment->title }}">
                                        <i class="ti ti-send me-1"></i>{{ __('Сдать работу') }}
                                    </button>
                                @elseif ($submission->status?->value === 'returned')
                                    <button type="button" class="btn btn-sm btn-warning k-action"
                                            data-lms-submit="{{ $assignment->id }}"
                                            data-lms-title="{{ $assignment->title }}">
                                        <i class="ti ti-rotate-clockwise me-1"></i>{{ __('Доработать') }}
                                    </button>
                                @else
                                    <button type="button" class="btn btn-sm btn-outline-primary k-action"
                                            data-lms-submit="{{ $assignment->id }}"
                                            data-lms-title="{{ $assignment->title }}">
                                        <i class="ti ti-pencil me-1"></i>{{ __('Обновить работу') }}
                                    </button>
                                @endif

                                @if (! empty($canManage) && $submission && in_array($submission->status?->value, ['submitted', 'returned', 'reviewed'], true))
                                    <details class="k-review">
                                        <summary><i class="ti ti-star me-1"></i>{{ __('Проверить') }}</summary>
                                        <form method="post" action="{{ route('lms.assignments.review', $submission->id) }}">
                                            @csrf
                                            <input type="number" name="score" class="form-control form-control-sm" min="0" max="{{ $assignment->max_score }}" placeholder="{{ __('Балл') }}" value="{{ $submission->score }}">
                                            <div class="d-flex gap-1">
                                                <button name="status" value="reviewed" class="btn btn-sm btn-success flex-fill">{{ __('Принять') }}</button>
                                                <button name="status" value="returned" class="btn btn-sm btn-outline-danger flex-fill">{{ __('Вернуть') }}</button>
                                            </div>
                                        </form>
                                    </details>
                                @endif
                            </div>
                        @endif
                    </article>
                @empty
                    <div class="crm-kanban__empty">{{ __('Пусто') }}</div>
                @endforelse
            </div>
        </section>
    @endforeach
</div>
