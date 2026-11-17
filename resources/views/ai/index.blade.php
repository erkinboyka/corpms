@extends('layouts.tabler')

@section('title', 'AI-ассистент')

@php
    $quickQuestions = [
        'Есть ли опоздания сегодня?',
        'Сколько просроченных задач?',
        'Какая численность в компании?',
        'Сколько заявок на отпуск в ожидании?',
    ];
@endphp

@section('content')
<div class="page-header d-print-none mb-3">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">Данные</div>
            <h2 class="page-title mt-1">AI-ассистент аналитики</h2>
            <div class="text-secondary mt-1">Отвечает только на основе данных, доступных вашему scope (п.68 ТЗ)</div>
        </div>
        <div class="col-auto d-print-none">
            <span class="crm-chip crm-chip--green"><i class="ti ti-shield-check me-1"></i>grounded-ответы</span>
        </div>
    </div>
</div>

<div class="row g-3">
    {{-- ===== Диалог ===== --}}
    <div class="col-lg-8">
        <div class="card crm-panel h-100 d-flex flex-column">
            <div class="card-header d-flex align-items-start justify-content-between gap-2">
                <div>
                    <div class="card-title mb-0">Диалог</div>
                    <div class="crm-panel__sub">последние {{ $history->count() }} вопросов · история хранится в ai_conversations</div>
                </div>
                <span class="crm-chip crm-chip--cyan"><i class="ti ti-sparkles me-1"></i>{{ $history->count() }} в истории</span>
            </div>
            <div class="card-body d-flex flex-column flex-grow-1">
                @if ($history->isEmpty())
                    @include('partials.empty-state', ['icon' => 'sparkles', 'title' => 'Вопросов пока нет', 'text' => 'Спросите про посещаемость, задачи, отпуска или численность — ответ соберётся из фактов вашего scope.'])
                @else
                    <div class="crm-chat crm-chat--grow">
                        @foreach ($history->reverse() as $item)
                            <div class="crm-msg crm-msg--me">
                                <div class="crm-msg__avatar"><i class="ti ti-user"></i></div>
                                <div class="crm-msg__bubble">
                                    {{ $item->question }}
                                    <div class="crm-msg__sources">
                                        <span class="crm-chip crm-chip--grey">{{ $item->created_at?->format('d.m.Y H:i') ?? '—' }}</span>
                                    </div>
                                </div>
                            </div>
                            <div class="crm-msg">
                                <div class="crm-msg__avatar"><i class="ti ti-sparkles"></i></div>
                                <div class="crm-msg__bubble">
                                    {{ $item->answer }}
                                    <div class="crm-msg__sources">
                                        <span class="crm-chip {{ $item->grounded ? 'crm-chip--green' : 'crm-chip--orange' }}">grounded</span>
                                        <span class="crm-chip crm-chip--grey"><i class="ti ti-database me-1"></i>факты scope</span>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                <form method="post" action="{{ route('ai.ask') }}" data-submit-guard class="crm-chat__dock mt-auto mb-0">
                    @csrf
                    <input type="text" name="question" class="form-control" maxlength="500" minlength="3" required
                           placeholder="Например: есть ли опоздания и сколько людей на месте сегодня?"
                           aria-label="Вопрос ассистенту">
                    <button class="btn btn-primary" style="flex: none;"><i class="ti ti-send me-1"></i>Спросить</button>
                </form>
            </div>

            <div class="card-footer">
                <div class="text-secondary small">Темы: посещаемость, задачи, отпуска, численность. Вопросы от 3 до 500 символов.</div>
            </div>
        </div>
    </div>

    {{-- ===== Подсказки и как это работает ===== --}}
    <div class="col-lg-4">
        <div class="card crm-panel mb-3">
            <div class="card-header">
                <div>
                    <div class="card-title mb-0">Быстрые вопросы</div>
                    <div class="crm-panel__sub">один клик — и ответ в диалоге</div>
                </div>
            </div>
            <div class="card-body d-flex flex-column gap-2">
                @foreach ($quickQuestions as $question)
                    <form method="post" action="{{ route('ai.ask') }}" data-submit-guard>
                        @csrf
                        <input type="hidden" name="question" value="{{ $question }}">
                        <button class="btn btn-ghost-secondary w-100 justify-content-start text-start" style="white-space: normal; text-align: left;">
                            <i class="ti ti-message-question me-1"></i>{{ $question }}
                        </button>
                    </form>
                @endforeach
            </div>
        </div>

        <div class="card crm-panel">
            <div class="card-header">
                <div>
                    <div class="card-title mb-0">Как это работает</div>
                    <div class="crm-panel__sub">принцип grounded без «галлюцинаций»</div>
                </div>
            </div>
            <div class="card-body">
                <div class="crm-timeline">
                    <div class="crm-timeline__item">
                        <div class="crm-timeline__time">1</div>
                        <div class="crm-timeline__title"><strong>Факты</strong></div>
                        <div class="crm-timeline__text">Собираются посещаемость, задачи, отпуска и численность в пределах вашего scope.</div>
                    </div>
                    <div class="crm-timeline__item">
                        <div class="crm-timeline__time">2</div>
                        <div class="crm-timeline__title"><strong>Ответ</strong></div>
                        <div class="crm-timeline__text">Формируется только из этих фактов: без LLM работает локальный аналитик, с LLM — факты уходят в промпт.</div>
                    </div>
                    <div class="crm-timeline__item">
                        <div class="crm-timeline__time">3</div>
                        <div class="crm-timeline__title"><strong>Проверка</strong></div>
                        <div class="crm-timeline__text">Каждый ответ помечен grounded, сохраняется вместе с вопросом и фиксируется в журнале (ai.asked).</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
