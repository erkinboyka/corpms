@extends('layouts.tabler')
@section('workspace_layout', 'stream')

@section('title', 'Просроченные задачи')

@section('content')
<div class="page-header d-print-none mb-3">
    <div class="row align-items-center g-2">
        <div class="col">
            <div class="crm-eyebrow" style="color: var(--tblr-danger);">Контроль сроков</div>
            <h1 class="page-title mt-1 text-danger">Просроченные задачи</h1>
            <div class="text-secondary mt-1">Дедлайн прошёл, а задача не закрыта · всего {{ $tasks->total() }}</div>
        </div>
    </div>
</div>

<div class="crm-queue">
    @forelse ($tasks as $task)
        @php
            $daysLate = $task->deadline !== null ? (int) $task->deadline->diffInDays(now(), false) : 0;
            $prioChip = ['critical' => 'crm-chip--red', 'high' => 'crm-chip--orange', 'medium' => 'crm-chip--cyan', 'low' => 'crm-chip--grey'][$task->priority->value] ?? 'crm-chip--grey';
            $tone = $task->priority->value === 'critical' || $daysLate > 7 ? 'var(--crm-danger)' : 'var(--crm-warning)';
        @endphp
        <div class="crm-queue__item" style="--c: {{ $tone }};">
            <span class="crm-entity__logo bm-deadline-icon">
                <i class="ti ti-alarm"></i>
            </span>
            <div class="crm-queue__body">
                <div class="crm-queue__title">
                    <a class="text-reset text-decoration-none" href="{{ route('tasks.show', $task) }}">{{ $task->title }}</a>
                    <span class="crm-chip crm-chip--grey ms-1">#{{ $task->number }}</span>
                </div>
                <div class="crm-queue__sub">
                    просрочено на <strong class="text-danger">{{ $daysLate }} дн.</strong>
                    · дедлайн {{ $task->deadline?->format('d.m.Y') ?? '—' }}
                    · автор {{ $task->author?->last_name ?? '—' }}
                    · {{ $task->department?->name ?? 'отдел не указан' }}
                </div>
            </div>
            <span class="crm-chip {{ $prioChip }} d-none d-md-inline-flex">{{ $crm_label('task_priority', $task->priority->value) }}</span>
            <span class="crm-chip crm-chip--red d-none d-lg-inline-flex">{{ $crm_label('task_status', $task->status->value) }}</span>
            <a class="btn btn-sm btn-primary" href="{{ route('tasks.show', $task) }}" title="Открыть задачу"><i class="ti ti-arrow-right"></i></a>
        </div>
    @empty
        <div class="crm-queue__item" style="--c: var(--crm-success);">
            <span class="crm-entity__logo" style="width:38px;height:38px;font-size:15px;border-radius:11px;"><i class="ti ti-circle-check"></i></span>
            <div class="crm-queue__body">
                <div class="crm-queue__title">Просроченных нет</div>
                <div class="crm-queue__sub">Все задачи закрыты в срок.</div>
            </div>
        </div>
    @endforelse
</div>

@if ($tasks->hasPages())
    <div class="d-flex align-items-center mt-3">
        <div class="text-secondary me-auto">Страница {{ $tasks->currentPage() }} из {{ $tasks->lastPage() }}</div>
        {{ $tasks->links('pagination::tabler') }}
    </div>
@endif
@endsection
