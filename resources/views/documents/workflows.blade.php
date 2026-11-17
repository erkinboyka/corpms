@extends('layouts.tabler')

@section('title', 'Маршруты согласования')

@php
    $actionLabels = ['approve' => 'Согласовать', 'review' => 'Проверить', 'sign' => 'Подписать'];
    $actorLabels = ['role' => 'Роль', 'department' => 'Отдел', 'employee' => 'Сотрудник', 'author' => 'Автор документа', 'ceo' => 'Генеральный директор'];
@endphp

@section('content')
<div class="page-header d-print-none mb-3">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">Документооборот</div>
            <h2 class="page-title mt-1">Маршруты согласования</h2>
            <div class="text-secondary mt-1">Линейные цепочки шагов, через которые проходят документы</div>
        </div>
        <div class="col-auto d-print-none">
            <a class="btn btn-ghost-secondary me-1" href="{{ route('documents.index') }}"><i class="ti ti-arrow-left me-1"></i>Документы</a>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newWorkflow"><i class="ti ti-plus me-1"></i>Создать маршрут</button>
        </div>
    </div>
</div>

<div class="row g-3">
    @forelse ($workflows as $workflow)
        <div class="col-xl-6">
            <div class="crm-form__section h-100 wf-card">
                <div class="d-flex align-items-start justify-content-between gap-2">
                    <h3 class="wf-card__title">{{ $workflow->name }}</h3>
                    <span class="crm-chip crm-chip--cyan flex-shrink-0">{{ $inWorkflow[$workflow->id] ?? 0 }} док.</span>
                </div>

                <div class="wf-card__cats">
                    @if ($workflow->applicable_categories)
                        @foreach ($workflow->applicable_categories as $category)
                            <span class="crm-chip crm-chip--grey"><i class="ti ti-folder me-1"></i>{{ $category }}</span>
                        @endforeach
                    @else
                        <span class="crm-chip crm-chip--grey"><i class="ti ti-world me-1"></i>Все категории</span>
                    @endif
                </div>

                {{-- шаги маршрута: нумерованная цепочка с исполнителем и действием --}}
                <ol class="wf-steps">
                    @foreach ($workflow->steps as $step)
                        <li class="wf-steps__item {{ $step->is_terminal ? 'wf-steps__item--final' : '' }}">
                            <span class="wf-steps__num">{{ $step->step_order }}</span>
                            <div class="wf-steps__body">
                                <div class="wf-steps__name">
                                    {{ $step->name }}
                                    @if ($step->is_signature_step)
                                        <span class="crm-chip crm-chip--orange"><i class="ti ti-signature me-1"></i>подпись</span>
                                    @endif
                                    @if ($step->is_terminal)
                                        <span class="crm-chip crm-chip--green"><i class="ti ti-flag me-1"></i>финал</span>
                                    @endif
                                </div>
                                <div class="wf-steps__meta">
                                    <span class="wf-steps__action wf-steps__action--{{ $step->required_action->value }}">
                                        <i class="ti ti-{{ ['approve' => 'circle-check', 'review' => 'eye', 'sign' => 'signature'][$step->required_action->value] ?? 'circle-check' }}"></i>
                                        {{ $actionLabels[$step->required_action->value] ?? $step->required_action->value }}
                                    </span>
                                    <span class="wf-steps__dot">·</span>
                                    <span class="wf-steps__actor">
                                        <i class="ti ti-{{ ['role' => 'key', 'department' => 'building-community', 'employee' => 'user', 'author' => 'user-edit', 'ceo' => 'crown'][$step->actor_type->value] ?? 'user' }}"></i>
                                        @if ($step->role)
                                            {{ $step->role->name }}
                                        @else
                                            {{ ['role' => 'по роли', 'department' => 'отдел', 'employee' => 'сотрудник', 'author' => 'автор', 'ceo' => 'генеральный директор'][$step->actor_type->value] ?? $step->actor_type->value }}
                                        @endif
                                    </span>
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ol>
            </div>
        </div>
    @empty
        <div class="col-12">
            @include('partials.empty-state', ['icon' => 'git-branch', 'title' => 'Маршрутов нет', 'text' => 'Создайте маршрут — цепочку согласования для документов.'])
        </div>
    @endforelse
</div>

{{-- ===== Модалка создания: 3-шаговый мастер ===== --}}
<div class="modal modal-blur fade" id="newWorkflow" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <form class="modal-content" method="post" action="{{ route('workflows.store') }}" data-submit-guard id="wf-wizard-form">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title">Новый маршрут согласования</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
            </div>

            <div class="modal-body">
                {{-- индикатор шагов --}}
                <ul class="nav crm-steps mb-3" id="wf-steps-nav">
                    <li class="nav-item"><span class="nav-link active" data-wf-step="1"><span class="crm-steps__num">1</span> Суть</span></li>
                    <li class="nav-item"><span class="nav-link" data-wf-step="2"><span class="crm-steps__num">2</span> Шаги</span></li>
                    <li class="nav-item"><span class="nav-link" data-wf-step="3"><span class="crm-steps__num">3</span> Проверка</span></li>
                </ul>

                {{-- Шаг 1: суть --}}
                <section data-wf-pane="1">
                    <div class="crm-form__field">
                        <label class="form-label" for="wf-name">Название<span class="req">*</span></label>
                        <input type="text" id="wf-name" name="name" class="form-control" required maxlength="150" placeholder="Приказы — согласование руководством">
                    </div>
                    <div class="crm-form__field mb-0">
                        <label class="form-label" for="wf-cats">Категории документов</label>
                        <input type="text" id="wf-cats" name="applicable_categories" class="form-control" maxlength="200"
                               placeholder="hr, admin — пусто = все документы"
                               autocomplete="off"
                               data-crm-tags data-crm-tags-hint="Enter — добавить категорию">
                    </div>
                </section>

                {{-- Шаг 2: динамические шаги маршрута --}}
                <section data-wf-pane="2" hidden>
                    <div class="crm-form__desc mt-0">Добавьте минимум один шаг. Максимум — {{ $maxSteps }}.</div>
                    <div id="wf-steps-list"></div>
                    <button type="button" class="btn btn-ghost-secondary mt-2" id="wf-step-add">
                        <i class="ti ti-plus me-1"></i>Добавить шаг
                    </button>
                    <template id="wf-step-tpl">
                        <div class="wf-step-card" data-wf-step-card>
                            <div class="wf-step-card__head">
                                <span class="wf-step-card__badge" data-wf-badge>1</span>
                                <strong data-wf-title>Шаг 1</strong>
                                <label class="ms-auto small text-secondary d-flex align-items-center gap-1 mb-0">
                                    <input type="checkbox" class="form-check-input" data-wf-sig> Шаг подписи
                                </label>
                                <button type="button" class="btn btn-sm btn-ghost-danger" data-wf-step-del aria-label="Удалить шаг" title="Удалить шаг">
                                    <i class="ti ti-trash"></i>
                                </button>
                            </div>
                            <div class="crm-form__row">
                                <div class="crm-form__field">
                                    <label class="form-label">Название шага<span class="req">*</span></label>
                                    <input type="text" class="form-control" data-wf-f="name" required maxlength="150" placeholder="Согласование руководителем">
                                </div>
                                <div class="crm-form__field">
                                    <label class="form-label">Кто действует</label>
                                    <select class="form-select" data-wf-f="actor_type">
                                        @foreach ($actorTypes as $actor)
                                            <option value="{{ $actor->value }}" @selected($actor->value === 'role')>{{ $actorLabels[$actor->value] ?? $actor->value }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="crm-form__row mb-0">
                                <div class="crm-form__field">
                                    <label class="form-label">Роль (если «Роль»)</label>
                                    <select class="form-select" data-wf-f="role_id">
                                        <option value="">—</option>
                                        @foreach ($roles as $role)
                                            <option value="{{ $role->id }}">{{ $role->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="crm-form__field">
                                    <label class="form-label">Действие</label>
                                    <select class="form-select" data-wf-f="required_action">
                                        @foreach ($actions as $action)
                                            <option value="{{ $action->value }}">{{ $actionLabels[$action->value] ?? $action->value }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>
                    </template>
                </section>

                {{-- Шаг 3: проверка --}}
                <section data-wf-pane="3" hidden>
                    <div class="crm-form__desc mt-0">Проверьте маршрут перед созданием.</div>
                    <div class="wf-review">
                        <div class="wf-review__row"><span>Название</span><strong data-wf-review="name">—</strong></div>
                        <div class="wf-review__row"><span>Категории</span><strong data-wf-review="cats">— все —</strong></div>
                        <div class="wf-review__row wf-review__row--block">
                            <span>Цепочка шагов</span>
                            <div class="d-flex flex-wrap align-items-center gap-1 mt-1" data-wf-review="chain"></div>
                        </div>
                    </div>
                </section>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-ghost-secondary" id="wf-back" hidden><i class="ti ti-arrow-left me-1"></i>Назад</button>
                <button type="button" class="btn btn-primary ms-auto" id="wf-next">Далее<i class="ti ti-arrow-right ms-1"></i></button>
                <button type="submit" class="btn btn-primary" id="wf-submit" hidden><i class="ti ti-check me-1"></i>Создать маршрут</button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
(function () {
    'use strict';
    var modal = document.getElementById('newWorkflow');
    if (!modal) { return; }

    var form = document.getElementById('wf-wizard-form');
    var list = document.getElementById('wf-steps-list');
    var tpl = document.getElementById('wf-step-tpl');
    var addBtn = document.getElementById('wf-step-add');
    var backBtn = document.getElementById('wf-back');
    var nextBtn = document.getElementById('wf-next');
    var submitBtn = document.getElementById('wf-submit');
    var nav = document.getElementById('wf-steps-nav');
    var MAX = {{ (int) $maxSteps }};
    var current = 1;

    function stepCards() { return Array.prototype.slice.call(list.querySelectorAll('[data-wf-step-card]')); }

    function reindex() {
        stepCards().forEach(function (card, i) {
            card.querySelector('[data-wf-badge]').textContent = i + 1;
            card.querySelector('[data-wf-title]').textContent = 'Шаг ' + (i + 1);
            var map = { name: 'name', actor_type: 'actor_type', role_id: 'role_id', required_action: 'required_action' };
            Object.keys(map).forEach(function (key) {
                var field = card.querySelector('[data-wf-f="' + key + '"]');
                if (field) { field.name = 'steps[' + i + '][' + map[key] + ']'; }
            });
            var sig = card.querySelector('[data-wf-sig]');
            sig.name = 'steps[' + i + '][is_signature_step]';
            sig.value = '1';
            card.querySelector('[data-wf-step-del]').disabled = stepCards().length <= 1;
        });
        addBtn.hidden = stepCards().length >= MAX;
    }

    function addStep() {
        if (stepCards().length >= MAX) { return; }
        list.appendChild(tpl.content.cloneNode(true));
        reindex();
        var cards = stepCards();
        var last = cards[cards.length - 1];
        last.querySelector('[data-wf-f="name"]').focus();
        last.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }

    addBtn.addEventListener('click', addStep);

    list.addEventListener('click', function (event) {
        var del = event.target.closest('[data-wf-step-del]');
        if (!del) { return; }
        var card = del.closest('[data-wf-step-card]');
        if (stepCards().length <= 1) { return; }
        card.remove();
        reindex();
    });

    /* видимость поля роли — только для actor=role */
    list.addEventListener('change', function (event) {
        var actor = event.target.closest('[data-wf-f="actor_type"]');
        if (!actor) { return; }
        var card = actor.closest('[data-wf-step-card]');
        var roleField = card.querySelector('[data-wf-f="role_id"]');
        var wrap = roleField.closest('.crm-form__field');
        wrap.style.opacity = actor.value === 'role' ? '' : '.45';
        roleField.disabled = actor.value !== 'role';
    });

    /* ---------- навигация ---------- */

    function showPane(n) {
        current = n;
        modal.querySelectorAll('[data-wf-pane]').forEach(function (pane) {
            pane.hidden = Number(pane.getAttribute('data-wf-pane')) !== n;
        });
        nav.querySelectorAll('[data-wf-step]').forEach(function (item) {
            item.classList.toggle('active', Number(item.getAttribute('data-wf-step')) === n);
        });
        backBtn.hidden = n === 1;
        nextBtn.hidden = n === 3;
        submitBtn.hidden = n !== 3;
        if (n === 3) { fillReview(); }
    }

    function validatePane(n) {
        if (n === 1) {
            var name = document.getElementById('wf-name');
            if (!name.value.trim()) { name.reportValidity(); name.focus(); return false; }
            return true;
        }
        if (n === 2) {
            var cards = stepCards();
            if (cards.length === 0) { return false; }
            for (var i = 0; i < cards.length; i++) {
                var field = cards[i].querySelector('[data-wf-f="name"]');
                if (!field.value.trim()) {
                    field.reportValidity();
                    field.focus();
                    field.scrollIntoView({ block: 'center', behavior: 'smooth' });
                    return false;
                }
                var actor = cards[i].querySelector('[data-wf-f="actor_type"]').value;
                var role = cards[i].querySelector('[data-wf-f="role_id"]');
                if (actor === 'role' && !role.value) {
                    role.reportValidity();
                    role.focus();
                    return false;
                }
            }
            return true;
        }
        return true;
    }

    function fillReview() {
        var name = document.getElementById('wf-name').value.trim() || '—';
        /* категории — виджет тегов: значение живёт в чипах, input пуст до отправки */
        var catsField = document.getElementById('wf-cats');
        var chipWrap = catsField ? catsField.closest('.crm-tags') : null;
        var chips = chipWrap ? chipWrap.querySelectorAll('.crm-tags__chip') : [];
        var cats = Array.prototype.map.call(chips, function (chip) {
            var copy = chip.cloneNode(true);
            var x = copy.querySelector('.crm-tags__x');
            if (x) { x.remove(); }
            return copy.textContent.trim();
        }).filter(Boolean).join(', ');
        modal.querySelector('[data-wf-review="name"]').textContent = name;
        modal.querySelector('[data-wf-review="cats"]').textContent = cats || '— все —';

        var chain = modal.querySelector('[data-wf-review="chain"]');
        chain.innerHTML = '';
        var labels = { approve: 'Согласовать', review: 'Проверить', sign: 'Подписать' };
        stepCards().forEach(function (card, i) {
            if (i > 0) {
                var arrow = document.createElement('i');
                arrow.className = 'ti ti-chevron-right text-secondary';
                chain.appendChild(arrow);
            }
            var chip = document.createElement('span');
            var stepName = card.querySelector('[data-wf-f="name"]').value.trim();
            var action = card.querySelector('[data-wf-f="required_action"]').value;
            var isSig = card.querySelector('[data-wf-sig]').checked;
            chip.className = 'crm-chip' + (isSig ? ' crm-chip--orange' : '');
            chip.textContent = (i + 1) + '. ' + stepName + ' · ' + (labels[action] || action);
            chain.appendChild(chip);
        });
    }

    nextBtn.addEventListener('click', function () {
        if (!validatePane(current)) { return; }
        if (current < 3) { showPane(current + 1); }
    });
    backBtn.addEventListener('click', function () {
        if (current > 1) { showPane(current - 1); }
    });

    /* сброс мастера при повторном открытии */
    modal.addEventListener('show.bs.modal', function () {
        showPane(1);
    });
    modal.addEventListener('hidden.bs.modal', function () {
        form.reset();
        stepCards().forEach(function (card, i) { if (i > 0) { card.remove(); } });
        reindex();
    });

    /* стартовый шаг */
    reindex();
    if (stepCards().length === 0) { addStep(); }
    showPane(1);
})();
</script>
<style>
    .crm-steps { display: flex; gap: .5rem; list-style: none; padding: 0; margin: 0; }
    .crm-steps .nav-link {
        display: inline-flex; align-items: center; gap: .45rem;
        padding: .35rem .75rem; border-radius: 999px;
        background: rgba(var(--tblr-secondary-rgb, 138, 148, 166), .12);
        color: var(--tblr-secondary, #8a94a6); font-size: .82rem; font-weight: 600;
        cursor: default;
    }
    .crm-steps .nav-link.active { background: var(--tblr-primary, #266cf2); color: #fff; }
    .crm-steps__num {
        display: inline-flex; align-items: center; justify-content: center;
        width: 1.25rem; height: 1.25rem; border-radius: 50%;
        background: rgba(255,255,255,.22); font-size: .72rem;
    }
    .crm-steps .nav-link:not(.active) .crm-steps__num { background: rgba(0,0,0,.08); }

    .wf-step-card {
        border: 1px solid var(--tblr-border-color, #e6e9ef);
        border-radius: .75rem;
        padding: .85rem 1rem .35rem;
        margin-bottom: .7rem;
        background: var(--tblr-bg-surface, #fff);
    }
    .wf-step-card__head {
        display: flex; align-items: center; gap: .55rem;
        margin-bottom: .6rem;
    }
    .wf-step-card__badge {
        display: inline-flex; align-items: center; justify-content: center;
        width: 1.5rem; height: 1.5rem; border-radius: 50%;
        background: rgba(var(--tblr-primary-rgb, 38, 108, 242), .12);
        color: var(--tblr-primary, #266cf2); font-size: .78rem; font-weight: 700;
    }

    .wf-review__row {
        display: flex; align-items: baseline; justify-content: space-between; gap: 1rem;
        padding: .55rem 0; border-bottom: 1px dashed var(--tblr-border-color, #e6e9ef);
        font-size: .88rem;
    }
    .wf-review__row span { color: var(--tblr-secondary, #8a94a6); }
    .wf-review__row--block { flex-direction: column; align-items: stretch; }
    .wf-review__row:last-child { border-bottom: 0; }
</style>
@endpush
@endsection

@push('styles')
<style>
    /* ===== Карточка маршрута: заголовок, категории, нумерованные шаги ===== */
    .wf-card__title { font-size: 15px; font-weight: 700; margin: 0; }
    .wf-card__cats { display: flex; flex-wrap: wrap; gap: 4px; margin: 8px 0 12px; }

    .wf-steps { list-style: none; margin: 0; padding: 0; }
    .wf-steps__item { position: relative; display: flex; gap: 10px; padding-bottom: 16px; }
    .wf-steps__item:last-child { padding-bottom: 0; }
    .wf-steps__item::before {
        content: ""; position: absolute; left: 13px; top: 30px; bottom: 2px; width: 2px;
        background: linear-gradient(180deg, rgba(var(--crm-accent-rgb), .35), rgba(var(--crm-accent-rgb), .1));
        border-radius: 2px;
    }
    .wf-steps__item:last-child::before { display: none; }

    .wf-steps__num {
        flex: 0 0 28px; height: 28px; border-radius: 50%;
        display: inline-flex; align-items: center; justify-content: center;
        font-size: 12.5px; font-weight: 700; position: relative; z-index: 1;
        background: rgba(var(--crm-accent-rgb), .14); color: var(--tblr-primary);
        box-shadow: 0 0 0 4px var(--tblr-card-bg, transparent);
    }
    .wf-steps__item--final .wf-steps__num { background: rgba(47, 179, 68, .16); color: #2fb344; }

    .wf-steps__body { min-width: 0; padding-top: 2px; }
    .wf-steps__name {
        display: flex; align-items: center; gap: 6px; flex-wrap: wrap;
        font-size: 13.5px; font-weight: 600;
    }
    .wf-steps__meta {
        display: flex; align-items: center; gap: 6px; flex-wrap: wrap;
        font-size: 12.5px; color: var(--tblr-secondary); margin-top: 3px;
    }
    .wf-steps__action { display: inline-flex; align-items: center; gap: 4px; font-weight: 600; }
    .wf-steps__action--approve { color: #2fb344; }
    .wf-steps__action--review { color: #4299e1; }
    .wf-steps__action--sign { color: #f76707; }
    .wf-steps__actor { display: inline-flex; align-items: center; gap: 4px; }
    .wf-steps__dot { opacity: .5; }
</style>
@endpush
