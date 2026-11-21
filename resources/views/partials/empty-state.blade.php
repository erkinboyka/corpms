{{-- Единое пустое состояние: иконка, заголовок, пояснение, действие.
     Использование: @include('partials.empty-state', ['icon' => 'task', 'title' => __('Задач нет'), 'text' => '...', 'actionUrl' => route('tasks.index'), 'actionLabel' => __('Создать задачу')]) --}}
<div class="crm-empty">
    <span class="crm-empty__icon"><i class="ti ti-{{ $icon ?? 'inbox' }}"></i></span>
    <div class="crm-empty__title">{{ $title ?? __('Нет данных') }}</div>
    @if (!empty($text))
        <div class="crm-empty__text">{{ $text }}</div>
    @endif
    @if (!empty($actionUrl))
        <a class="btn btn-primary btn-sm" href="{{ $actionUrl }}">{{ $actionLabel ?? __('Создать') }}</a>
    @endif
</div>
