@if (! empty($tips))
    <div class="lms-mentor">
        <div class="lms-mentor__head">
            <span class="lms-mentor__face"><i class="ti ti-sparkles" aria-hidden="true"></i></span>
            <div>
                <div class="lms-mentor__title">{{ __('Бumi подсказывает') }}</div>
                <div class="lms-mentor__sub">{{ __('Наставник видит пульс группы и ваш прогресс') }}</div>
            </div>
        </div>
        <div class="lms-mentor__tips">
            @foreach ($tips as $tip)
                <a class="lms-mentor__tip" href="{{ $tip['url'] }}">
                    <i class="ti ti-{{ $tip['icon'] }}" aria-hidden="true"></i>
                    <span>
                        <strong>{{ $tip['title'] }}</strong>
                        <small>{{ $tip['text'] }}</small>
                    </span>
                    <i class="ti ti-arrow-right lms-mentor__arrow" aria-hidden="true"></i>
                </a>
            @endforeach
        </div>
    </div>
@endif
