{{-- Рекурсивный узел оргструктуры: $node, $depth. Дети ищутся в $tree (общий scope include). --}}
@php
    $kids = $tree->where('parent_id', $node->id)->sortBy('name')->values();
    $hasKids = $kids->count() > 0;
    $initials = mb_strtoupper(mb_substr($node->name, 0, 2));
@endphp
<li class="crm-org__item" data-depth="{{ $depth }}">
    <details class="crm-org__branch" @if($depth === 0 || $hasKids) open @endif>
        <summary class="crm-org__node">
            <span class="crm-org__toggle" aria-hidden="true"><i class="ti ti-chevron-right"></i></span>
            <span class="crm-entity__logo crm-org__logo">{{ $initials }}</span>
            <span class="crm-org__body">
                <span class="crm-org__name">{{ $node->name }}</span>
                <span class="crm-org__sub">код {{ $node->code }}@if($node->director) · {{ $node->director->last_name }} {{ $node->director->first_name }}@endif</span>
            </span>
            <span class="crm-org__stats d-none d-md-flex">
                <span class="crm-chip" title="Активных сотрудников"><i class="ti ti-users me-1"></i>{{ $node->active_employees_count }}</span>
                @if ($node->director_id)
                    <span class="crm-chip crm-chip--green"><i class="ti ti-user-check me-1"></i>рук.</span>
                @else
                    <span class="crm-chip crm-chip--grey">без руководителя</span>
                @endif
            </span>
        </summary>
        <div class="crm-org__content">
          <div class="crm-org__content-inner">
            @if ($hasKids)
                <ul class="crm-org">
                    @foreach ($kids as $kid)
                        @include('departments.orgchart-node', ['node' => $kid, 'depth' => $depth + 1])
                    @endforeach
                </ul>
            @endif
            <div class="crm-org__panel">
                <div class="crm-org__panel-head">
                    <i class="ti ti-users"></i>
                    <span>Сотрудники · {{ $node->activeEmployees->count() }}</span>
                    @unless ($hasKids)
                        <span class="crm-org__panel-note">дочерних подразделений нет</span>
                    @endunless
                </div>
                @forelse ($node->activeEmployees as $worker)
                    <a class="crm-org__worker" href="{{ route('employees.show', $worker) }}">
                        <span class="crm-entity__logo crm-org__worker-avatar">{{ mb_strtoupper(mb_substr($worker->first_name, 0, 1) . mb_substr($worker->last_name, 0, 1)) }}</span>
                        <span class="crm-org__worker-body">
                            <span class="crm-org__worker-name">{{ $worker->last_name }} {{ $worker->first_name }}</span>
                            <span class="crm-org__worker-pos">{{ $worker->position?->name ?? 'должность не назначена' }}</span>
                        </span>
                        <i class="ti ti-chevron-right crm-org__worker-go" aria-hidden="true"></i>
                    </a>
                @empty
                    <div class="crm-org__panel-empty">Активных сотрудников в подразделении нет</div>
                @endforelse
            </div>
          </div>
        </div>
    </details>
</li>
