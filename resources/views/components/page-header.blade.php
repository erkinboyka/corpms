{{-- Единый заголовок страницы BM Systems.
     Использование:
       <x-page-header eyebrow="{{ __("Люди") }}" title="{{ __("Сотрудники") }}" subtitle="...">
           <button class="btn btn-primary">Действие</button>
       </x-page-header>
--}}
@props([
    'eyebrow' => '',
    'title' => '',
    'subtitle' => '',
])
<div class="page-header d-print-none mb-4">
    <div class="row g-2 align-items-end">
        <div class="col">
            @if ($eyebrow !== '')
                <div class="crm-eyebrow">{{ $eyebrow }}</div>
            @endif
            <h1 class="page-title mt-1">{{ $title }}</h1>
            @if ($subtitle !== '')
                <div class="text-secondary mt-1">{{ $subtitle }}</div>
            @endif
        </div>
        @if (! $slot->isEmpty())
            <div class="col-auto d-flex gap-2 align-items-center">
                {{ $slot }}
            </div>
        @endif
    </div>
</div>
