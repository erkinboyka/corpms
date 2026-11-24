{{-- Рекурсивный узел оргструктуры. Ожидает: $node, $depth. Использует $tree из родительского include. --}}
@php
    $kids = $tree->where('parent_id', $node->id)->sortBy('name')->values();
    $hasKids = $kids->isNotEmpty();
    $compact = ! $hasKids && $node->activeEmployees->count() <= 8;
    $initials = mb_strtoupper(mb_substr($node->name, 0, 2));
@endphp
<li class="crm-org__item" data-depth="{{ $depth }}">
    <details @class(['crm-org__branch', 'crm-org__branch--compact' => $compact]) @if($depth === 0 || $hasKids) open @endif>
        <summary class="crm-org__node">
            <span class="crm-org__toggle" aria-hidden="true"><i class="ti ti-chevron-right"></i></span>
            <span class="crm-entity__logo crm-org__logo">{{ $initials }}</span>
            <span class="crm-org__body">
                <span class="crm-org__name">{{ $node->name }}</span>
                <span class="crm-org__sub">{{ __('код') }} {{ $node->code }}@if($node->director) · {{ $node->director->last_name }} {{ $node->director->first_name }}@endif</span>
            </span>
            <span class="crm-org__stats d-none d-md-flex">
                <span class="crm-chip" title="{{ __("Активных сотрудников в отделе") }}"><i class="ti ti-users me-1"></i>{{ $node->active_employees_count }}</span>
                @if ($node->director_id)
                    <span class="crm-chip crm-chip--green"><i class="ti ti-user-check me-1"></i>{{ __('рук') }}</span>
                @else
                    <span class="crm-chip crm-chip--grey">{{ __('без руководителя') }}</span>
                @endif
            </span>
        </summary>
        <div class="crm-org__contents">
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
                    <span>{{ __('Сотрудники') }} {{ $node->activeEmployees->count() }}</span>
                    @unless ($hasKids)
                        <span class="crm-org__panel-note">{{ __('Конечный отдел: подразделений нет') }}</span>
                    @endunless
                </div>
                @php
                    $directorId = $node->director_id;
                    $orgWorkers = $node->activeEmployees->sortBy(function ($w) use ($directorId) {
                        $name = mb_strtolower((string) ($w->position?->name ?? ''));
                        $isLead = $directorId && (int) $w->id === (int) $directorId;
                        $isHead = str_contains($name, 'директор') || str_contains($name, 'head') || str_contains($name, 'lead');
                        $index = $isLead || $isHead ? '0' : '1';
                        return $index . mb_strtolower($w->last_name . ' ' . $w->first_name);
                    })->values();
                @endphp
                @forelse ($orgWorkers as $worker)
                    @php $isLead = ($directorId && (int) $worker->id === (int) $directorId) || str_contains(mb_strtolower((string) ($worker->position?->name ?? '')), 'директор'); @endphp
                    <a class="crm-org__worker {{ $isLead ? 'crm-org__worker--lead' : '' }}" href="{{ route('employees.show', $worker) }}">
                        <span class="crm-entity__logo crm-org__worker-avatar">{{ mb_strtoupper(mb_substr($worker->first_name, 0, 1) . mb_substr($worker->last_name, 0, 1)) }}</span>
                        <span class="crm-org__worker-body">
                            <span class="crm-org__worker-name">{{ $worker->last_name }} {{ $worker->first_name }}@if($isLead) <i class="ti ti-crown ms-1" aria-hidden="true"></i>@endif</span>
                            <span class="crm-org__worker-pos">{{ $worker->position?->name ?? __('должность не назначена') }}@if($isLead) <span class="crm-org__worker-lead-badge">{{ __('Руководитель отдела') }}</span>@endif</span>
                        </span>
                        <i class="ti {{ $isLead ? 'ti-crown' : 'ti-chevron-right' }} crm-org__worker-go" aria-hidden="true"></i>
                    </a>
                @empty
                    <div class="crm-org__panel-empty">{{ __('Активных сотрудников в подразделении нет') }}</div>
                @endforelse
            </div>
        </div>
    </details>
</li>
