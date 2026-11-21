@php
    $columns = [
        'draft' => ['title' => __('Черновик'), 'icon' => 'notes', 'class' => 'cyan'],
        'submitted' => ['title' => __('На проверке'), 'icon' => 'send', 'class' => 'orange'],
        'reviewed' => ['title' => __('Проверено'), 'icon' => 'circle-check', 'class' => 'green'],
        'returned' => ['title' => __('На доработке'), 'icon' => 'rotate-clockwise', 'class' => 'red'],
    ];
@endphp
<div class="lms-board">
    @foreach ($columns as $key => $column)
        <section class="lms-board__col" data-status="{{ $key }}">
            <header class="lms-board__head">
                <i class="ti ti-{{ $column['icon'] }}" aria-hidden="true"></i>
                <span>{{ $column['title'] }}</span>
                <span class="lms-board__count">{{ count($board[$key] ?? []) }}</span>
            </header>
            <div class="lms-board__list">
                @forelse ($board[$key] ?? [] as $card)
                    @php $assignment = $card['assignment']; $submission = $card['submission']; @endphp
                    <article class="lms-board__card">
                        <div class="lms-board__card-top">
                            <span class="crm-chip crm-chip--{{ $column['class'] }}">{{ $assignment->course?->title ?? __('Курс') }}</span>
                            @if ($assignment->due_at)
                                <span class="lms-board__due"><i class="ti ti-clock me-1"></i>{{ $assignment->due_at->format('d.m') }}</span>
                            @endif
                        </div>
                        <h4 class="lms-board__title">{{ $assignment->title }}</h4>
                        @if ($submission?->employee)
                            <div class="lms-board__author">
                                <span class="lms-board__avatar">{{ mb_substr($submission->employee->first_name, 0, 1) }}{{ mb_substr($submission->employee->last_name, 0, 1) }}</span>
                                {{ trim($submission->employee->last_name.' '.$submission->employee->first_name) }}
                            </div>
                        @endif
                        @if ($submission?->status?->value === 'reviewed')
                            <div class="lms-board__score"><i class="ti ti-star me-1"></i>{{ $submission->score ?? '—' }} / {{ $assignment->max_score }}</div>
                        @endif

                        @if (! $submission)
                            <button type="button" class="btn btn-sm btn-primary mt-2 w-100"
                                    data-lms-submit="{{ $assignment->id }}"
                                    data-lms-title="{{ $assignment->title }}">
                                <i class="ti ti-send me-1"></i>{{ __('Сдать работу') }}
                            </button>
                        @elseif ($submission->status?->value === 'returned')
                            <button type="button" class="btn btn-sm btn-warning mt-2 w-100"
                                    data-lms-submit="{{ $assignment->id }}"
                                    data-lms-title="{{ $assignment->title }}">
                                <i class="ti ti-rotate-clockwise me-1"></i>{{ __('Доработать') }}
                            </button>
                        @elseif ($submission->status?->value === 'submitted')
                            <button type="button" class="btn btn-sm btn-outline-primary mt-2 w-100"
                                    data-lms-submit="{{ $assignment->id }}"
                                    data-lms-title="{{ $assignment->title }}">
                                <i class="ti ti-pencil me-1"></i>{{ __('Обновить работу') }}
                            </button>
                        @endif

                        @if (! empty($canManage) && $submission && in_array($submission->status?->value, ['submitted', 'returned', 'reviewed'], true))
                            <form method="post" action="{{ route('lms.assignments.review', $submission->id) }}" class="lms-board__review mt-2">
                                @csrf
                                <input type="number" name="score" class="form-control form-control-sm" min="0" max="{{ $assignment->max_score }}" placeholder="{{ __('Балл') }}" value="{{ $submission->score }}">
                                <div class="d-flex gap-1 mt-1">
                                    <button name="status" value="reviewed" class="btn btn-sm btn-success flex-fill">{{ __('Принять') }}</button>
                                    <button name="status" value="returned" class="btn btn-sm btn-outline-danger flex-fill">{{ __('Вернуть') }}</button>
                                </div>
                            </form>
                        @endif
                    </article>
                @empty
                    <div class="lms-board__empty">{{ __('Пусто') }}</div>
                @endforelse
            </div>
        </section>
    @endforeach
</div>

<div class="modal modal-blur fade" id="lmsSubmitModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <form method="post" action="#" data-lms-submit-form data-base="{{ route('lms.assignments.submit', ['assignment' => '__ID__']) }}">
                @csrf
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title mb-0">{{ __('Сдать практическое задание') }}</h5>
                        <div class="text-secondary mt-1" data-lms-submit-title></div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Закрыть') }}"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label" for="lms-submit-body">{{ __('Что сделали') }}</label>
                        <textarea id="lms-submit-body" name="body" class="form-control" rows="6" required placeholder="{{ __('Коротко опишите решение, выводы и ссылки на артефакты') }}"></textarea>
                    </div>
                    <div class="mb-0">
                        <label class="form-label" for="lms-submit-link">{{ __('Ссылка на работу') }}</label>
                        <input id="lms-submit-link" name="link" type="url" class="form-control" placeholder="https://…">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-ghost-secondary" data-bs-dismiss="modal">{{ __('Отмена') }}</button>
                    <button class="btn btn-primary" type="submit"><i class="ti ti-send me-1"></i>{{ __('Отправить') }}</button>
                </div>
            </form>
        </div>
    </div>
</div>
