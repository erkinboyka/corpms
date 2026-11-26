@foreach($blocks as $block)
    @switch($block['type'] ?? '')
        @case('templates')
            <div class="bumi-tool" data-bumi-library>
                <strong>{{ __('Каталог шаблонов Bumi') }}</strong>
                <p class="bumi-tool__hint">{{ __('Выберите вид материала и номер шаблона, уточните тему — незавершённый вопрос в поле ввода сохранится.') }}</p>
                <label>{{ __('Шаблон') }}<select data-template-command aria-label="{{ __('Шаблон') }}">
                    @foreach(['report'=>[__('Отчёты'),__('Подготовь отчёт')], 'deck'=>[__('Презентации'),__('Создай презентацию')], 'chart'=>[__('Диаграммы'),__('Построй диаграмму')]] as $kind=>[$group,$verb])
                        <optgroup label="{{ $group }}">@foreach(\App\Domains\Ai\Services\BumiTemplates::names($kind) as $index=>$name)
                            <option value="{{ $verb }}, {{ __('шаблон') }} №{{ $index+1 }}">{{ $index+1 }}. {{ $name }}</option>
                        @endforeach</optgroup>
                    @endforeach
                </select></label>
                <label>{{ __('Тема / данные') }}<input data-template-topic maxlength="380" value="{{ __("по задачам") }}" placeholder="{{ __("По посещаемости за неделю") }}"></label>
                <button class="btn btn-primary" type="button" data-template-use><i class="ti ti-layout-grid" aria-hidden="true"></i> {{ __('Создать по шаблону') }}</button>
                <span class="bumi-tool__status" data-template-status role="status" aria-live="polite"></span>
            </div>
            @break
        @case('sources')
            <div class="bumi-tool bumi-sources">
                <strong>{{ __('Источники') }}</strong><small>{{ $block['context'] }}</small>
                @foreach($block['items'] as $item)
                    @if(preg_match('~^/(?!/)[^\x00-\x20\\\\]*$~D', $item['url'] ?? ''))
                        <a class="bumi-guide-link" data-bumi-guide href="{{ $item['url'] }}">{{ $item['label'] }} <i class="ti ti-arrow-up-right" aria-hidden="true"></i></a>
                    @else<span>{{ $item['label'] }}</span>@endif
                @endforeach
            </div>
            @break
        @case('signature')
            @php($status = isset($signatureStates) ? ($signatureStates[$block['request']] ?? 'closed') : 'pending')
            <div class="bumi-tool">
                <strong>{{ $block['title'] }}</strong><small>{{ $block['number'] }} {{ __('Версия') }} {{ $block['version'] }}</small>
                <a href="{{ route('documents.show', $block['document']) }}">{{ __('Открыть документ') }}</a>
                @if(!in_array($status, ['pending','partial'], true))
                    <span>{{ $status === 'signed' ? __('Подписано') : __('Запрос закрыт') }}</span>
                @elseif($block['internal'])
                    <form method="post" action="{{ route('documents.signature.sign', [$block['document'], $block['request']]) }}" data-bumi-sign>
                        @csrf
                        @if($block['otp'])<label>{{ __('Код подтверждения') }}<input name="otp_code" autocomplete="one-time-code" inputmode="numeric" required></label>@endif
                        <button class="btn btn-primary" type="submit">{{ __('Подписать эту версию') }}</button>
                        <span role="status"></span>
                    </form>
                @else
                    <small>{{ __('Для этого способа подписи откройте карточку документа') }}</small>
                @endif
            </div>
            @break
        @case('task_draft')
            <form method="post" action="{{ route('tasks.store') }}" class="bumi-tool" data-submit-guard>
                @csrf<input type="hidden" name="type" value="task">
                <label>{{ __('Название задачи') }}<input name="title" value="{{ $block['title'] }}" required maxlength="255"></label>
                <label>{{ __('Исполнитель') }}<select name="employee_id" required>
                    @php($assignee = array_key_exists('assignee', $block) ? $block['assignee'] : auth()->user()->employee->id)
                    <option value="" @selected(!isset($assignees[$assignee]))>{{ __('Выберите исполнителя') }}</option>
                    @foreach($assignees as $id=>$name)<option value="{{ $id }}" @selected($id == $assignee)>{{ $name }}</option>@endforeach
                </select></label>
                <div class="bumi-fields"><label>{{ __('Срок') }}<input type="date" name="deadline" value="{{ $block['deadline'] }}"></label>
                <label>{{ __('Приоритет') }}<select name="priority">@foreach(['low'=>__('Низкий'),'medium'=>__('Средний'),'high'=>__('Высокий'),'critical'=>__('Критический')] as $id=>$name)<option value="{{ $id }}" @selected($id === $block['priority'])>{{ $name }}</option>@endforeach</select></label></div>
                <button type="submit" class="btn btn-primary">{{ __('Создать задачу') }}</button>
            </form>
            @break
        @case('leave_draft')
            <form method="post" action="{{ route('leaves.request') }}" class="bumi-tool" data-submit-guard>
                @csrf<input type="hidden" name="type" value="{{ $block['kind'] }}">
                <strong>{{ $block['kind'] === 'day_off' ? __('Мой отгул') : __('Мой отпуск') }}</strong>
                <div class="bumi-fields"><label>{{ __('Начало') }}<input type="date" name="from_date" required value="{{ $block['start'] }}"></label>
                <label>{{ __('Окончание') }}<input type="date" name="to_date" required value="{{ $block['end'] }}"></label></div>
                <label>{{ __('Комментарий') }}<input name="reason" value="{{ $block['reason'] ?? '' }}" maxlength="500"></label>
                <button type="submit" class="btn btn-primary">{{ __('Отправить заявку') }}</button>
            </form>
            @break
        @case('document_draft')
            <form method="post" action="{{ route('documents.store') }}" class="bumi-tool" data-submit-guard>
                @csrf
                <label>{{ __('Заголовок документа') }}<input name="title" value="{{ $block['title'] }}" required maxlength="255"></label>
                <label>{{ __('Текст') }}<textarea name="body" rows="4">{{ $block['body'] }}</textarea></label>
                <label>{{ __('Категория') }}<select name="category_id" required><option value="">{{ __('Выберите категорию') }}</option>@foreach($block['categories'] as $id=>$name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></label>
                <button type="submit" class="btn btn-primary">{{ __('Сохранить документ') }}</button>
            </form>
            @break
        @case('file')
            <div class="bumi-tool">
                @if ($block['format'] === 'PNG')<img class="bumi-preview" src="{{ route('ai.files.download', ['file'=>$block['file'],'preview'=>1]) }}" alt="{{ $block['title'] }}" loading="lazy">@endif
                <a href="{{ route('ai.files.download', $block['file']) }}" download><i class="ti ti-download" aria-hidden="true"></i> {{ __('Скачать') }} {{ $block['format'] }} · {{ $block['title'] }}</a>
            </div>
            @break
        @case('actions')
            <div class="ai-actions">
                @if($block['guide'] ?? false)<strong>{{ $block['title'] ?? __('Раздел системы') }}</strong>@endif
                @foreach($block['items'] ?? [] as $action)
                    @if(preg_match('~^/(?!/)[^\x00-\x20\\\\]*$~D', $action['url'] ?? ''))
                        <a class="ai-action" @if($block['guide'] ?? false) data-bumi-guide @endif href="{{ $action['url'] }}">{{ $action['label'] }}</a>
                    @endif
                @endforeach
            </div>
            @break
        @case('chart')
            @if(!empty($block['data']) && isset($block['style']))
                <figure class="bumi-graphic">
                    @include('ai.graphic', ['title'=>$block['title'], 'series'=>$block['data'], 'style'=>$block['style']])
                    <details><summary>{{ __('Значения') }}</summary><dl>@foreach($block['data'] as $item)<div><dt>{{ $item['label'] }}</dt><dd>{{ $item['value'] }}</dd></div>@endforeach</dl></details>
                </figure>
            @elseif(!empty($block['data']))
                <figure class="bumi-plot"><figcaption>{{ $block['title'] ?? __('Диаграмма') }}</figcaption>
                @php($max = max(1, ...array_map('floatval', array_column($block['data'], 'value'))))
                @foreach($block['data'] as $point)
                    <div><span>{{ $point['label'] }}</span><b>{{ $point['value'] }}</b><i style="--bar:{{ round(max(0,(float)$point['value'])/$max*100, 2) }}%"></i></div>
                @endforeach</figure>
            @endif
            @break
        @case('table')
            <table class="ai-table"><caption>{{ $block['title'] ?? '' }}</caption><tbody>@foreach($block['rows'] ?? [] as $row)<tr>@foreach($row as $cell)<td>{{ $cell }}</td>@endforeach</tr>@endforeach</tbody></table>
            @break
        @case('text')
            <div class="bumi-tool"><strong>{{ $block['title'] ?? '' }}</strong><pre class="ai-text">{{ $block['content'] ?? '' }}</pre></div>
            @break
    @endswitch
@endforeach
