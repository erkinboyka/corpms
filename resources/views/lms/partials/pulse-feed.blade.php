@php
    $icons = [
        'course' => 'school', 'lesson' => 'book', 'lesson_done' => 'circle-check',
        'enroll' => 'user-plus', 'board' => 'pencil', 'meeting' => 'video',
        'assignment' => 'clipboard-list', 'submit' => 'send', 'review' => 'star',
    ];
    $pollUrl = $pollUrl ?? '';
    $pollMs = $pollMs ?? 3000;
@endphp
<div class="lms-pulse-feed" @if ($pollUrl !== '') data-lms-pulse="{{ $pollUrl }}" data-poll-ms="{{ $pollMs }}" @endif>
    @forelse ($activities as $activity)
        <div class="lms-pulse-item @if (! $loop->first) is-dim @endif" data-pulse-id="{{ $activity['id'] ?? 0 }}">
            <span class="lms-pulse-item__icon lms-pulse-item__icon--{{ $activity['type'] }}">
                <i class="ti ti-{{ $icons[$activity['type']] ?? 'activity' }}" aria-hidden="true"></i>
            </span>
            <div class="lms-pulse-item__body">
                <div class="lms-pulse-item__label">{{ $activity['label'] }}</div>
                <div class="lms-pulse-item__meta">
                    @if (! empty($activity['author'])) {{ $activity['author'] }} · @endif
                    @if (! empty($activity['created_at'])) {{ $activity['created_at'] }} @endif
                </div>
            </div>
            @if (! empty($activity['course_id']))
                <a class="lms-pulse-item__link" href="{{ route('lms.courses.show', $activity['course_id']) }}" aria-label="{{ __('Открыть курс') }}">
                    <i class="ti ti-arrow-up-right" aria-hidden="true"></i>
                </a>
            @endif
        </div>
    @empty
        @include('partials.empty-state', ['icon' => 'pulse', 'title' => __('Пульс пока тихий'), 'text' => __('Первый штрих на доске или пройденный урок появятся здесь')])
    @endforelse
</div>
