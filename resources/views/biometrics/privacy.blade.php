@extends('layouts.tabler')
@section('workspace_layout', 'configuration')

@section('title', 'Приватность биометрии')

@section('content')
<div class="page-header d-print-none mb-3">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow">Биометрические терминалы</div>
            <h1 class="page-title mt-1">Приватность данных</h1>
            <div class="text-secondary mt-1">Два правила: кто может отметиться и сколько данных хранить.</div>
        </div>
        <div class="col-auto d-print-none">
            <a class="btn btn-ghost-secondary" href="{{ route('biometrics.index') }}"><i class="ti ti-arrow-left me-1"></i>К устройствам</a>
        </div>
    </div>
</div>

{{-- ===== Статус: два больших «как сейчас» ===== --}}
<div class="row g-3 mb-3">
    <div class="col-md-6">
        <div class="card crm-panel h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <span class="crm-kpi__icon" style="width:3rem;height:3rem;font-size:1.4rem;background:{{ $requireLiveness ? 'rgba(47,179,68,.14)' : 'rgba(151,163,182,.16)' }};color:{{ $requireLiveness ? 'var(--crm-success)' : 'var(--crm-text-secondary)' }}">
                    <i class="ti ti-{{ $requireLiveness ? 'face-shield' : 'face-id-off' }}"></i>
                </span>
                <div>
                    <div class="crm-kpi__label">Живое лицо</div>
                    <div class="h3 mb-0">{{ $requireLiveness ? 'Проверяется' : 'Не проверяется' }}</div>
                    <div class="text-secondary small">{{ $requireLiveness ? 'Фото и маску терминал не примет' : 'Любой может отметиться за другого' }}</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card crm-panel h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <span class="crm-kpi__icon" style="width:3rem;height:3rem;font-size:1.4rem;background:rgba(66,153,225,.14);color:var(--crm-info)">
                    <i class="ti ti-clock-hour-4"></i>
                </span>
                <div>
                    <div class="crm-kpi__label">Хранение</div>
                    <div class="h3 mb-0">{{ $imageRetentionDays }} дн. фото · {{ $eventRetentionDays }} дн. отметок</div>
                    <div class="text-secondary small">Старое удаляется автоматически</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <form method="post" action="{{ route('biometrics.privacy.save') }}" data-submit-guard class="crm-form">
            @csrf

            <div class="crm-form__section">
                <h3>1. Кто может отметиться</h3>
                <label class="crm-toggle">
                    <input type="hidden" name="require_liveness" value="0">
                    <input class="form-check-input" type="checkbox" name="require_liveness" value="1" id="requireLiveness" @checked($requireLiveness)>
                    <span class="crm-toggle__body">
                        <span class="crm-toggle__title">Требовать живое лицо</span>
                        <span class="crm-toggle__desc">Терминал сверяет лицо вживую. Фото со снимка или маска — отметка не поставится.</span>
                    </span>
                </label>
            </div>

            <div class="crm-form__section">
                <h3>2. Сколько хранить</h3>
                <div class="crm-form__row">
                    <div class="crm-form__field">
                        <label class="form-label" for="img-ret">Фото, дней</label>
                        <input type="number" id="img-ret" name="image_retention_days" class="form-control" value="{{ $imageRetentionDays }}" min="1" max="3650" required>
                        <div class="form-hint">от 1 дня</div>
                    </div>
                    <div class="crm-form__field">
                        <label class="form-label" for="evt-ret">Отметки, дней</label>
                        <input type="number" id="evt-ret" name="event_retention_days" class="form-control" value="{{ $eventRetentionDays }}" min="30" max="3650" required>
                        <div class="form-hint">от 30 дней</div>
                    </div>
                </div>
            </div>

            <div class="crm-form__section mb-0">
                <h3>3. Удалить старое сейчас</h3>
                <label class="crm-toggle">
                    <input class="form-check-input" type="checkbox" name="purge_now" value="1" id="purgeNow">
                    <span class="crm-toggle__body">
                        <span class="crm-toggle__title">Очистить просроченное</span>
                        <span class="crm-toggle__desc">Всё, что уже старше срока, удаляется сразу и безвозвратно.</span>
                    </span>
                </label>
            </div>

            <div class="crm-form__footer crm-form__footer--card">
                <button class="btn btn-primary"><i class="ti ti-device-floppy me-1"></i>Сохранить</button>
                <span class="text-secondary small ms-2">Сейчас: {{ $imagesWithRef }} фото и {{ $eventRetentionDays }}-дневные отметки</span>
            </div>
        </form>
    </div>

    <div class="col-lg-5">
        <div class="card crm-panel">
            <div class="card-header">
                <div class="card-title mb-0">Что будет дальше</div>
            </div>
            <div class="card-body">
                <div class="crm-timeline">
                    <div class="crm-timeline__item">
                        <div class="crm-timeline__time">1</div>
                        <div class="crm-timeline__title"><strong>Новые отметки</strong></div>
                        <div class="crm-timeline__text">Идут по новым правилам сразу после сохранения.</div>
                    </div>
                    <div class="crm-timeline__item">
                        <div class="crm-timeline__time">2</div>
                        <div class="crm-timeline__title"><strong>Старые данные</strong></div>
                        <div class="crm-timeline__text">Сами стираются, когда истечёт срок. Всего хранится: {{ $imagesWithRef }} фото.</div>
                    </div>
                    <div class="crm-timeline__item">
                        <div class="crm-timeline__time">3</div>
                        <div class="crm-timeline__title"><strong>Первое событие</strong></div>
                        <div class="crm-timeline__text">{{ $oldestEventAt ? \Illuminate\Support\Carbon::parse($oldestEventAt)->format('d.m.Y') : 'нет событий' }} — самая ранняя отметка в журнале.</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="crm-note mt-3">
            <span class="crm-note__icon"><i class="ti ti-alert-triangle"></i></span>
            <span><strong class="d-block mb-1">Осторожно</strong><span class="text-secondary">«Очистить сейчас» удаляет файлы навсегда — восстановить их нельзя.</span></span>
        </div>
    </div>
</div>
@endsection
