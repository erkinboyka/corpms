{{--
    Боковая AI-панель (док справа). Подключается из layouts.tabler вместо старой модалки.
    Управление, отправка и рендер блоков — public/assets/js/ai-panel.js.
--}}
<div id="ai-panel-scrim" class="ai-panel-scrim" data-ai-close aria-hidden="true"></div>

<aside id="ai-panel" class="ai-panel" aria-label="AI-ассистент" aria-hidden="true">
    <header class="ai-panel__header">
        <div class="ai-panel__badge"><i class="ti ti-sparkles"></i></div>
        <div class="ai-panel__titles">
            <div class="ai-panel__title">AI-ассистент</div>
            <div class="ai-panel__sub">на основе данных компании</div>
        </div>
        <a class="ai-panel__icon" href="{{ route('ai.index') }}" title="Весь диалог" aria-label="Вся история диалога">
            <i class="ti ti-history"></i>
        </a>
        <button type="button" class="ai-panel__icon" data-ai-close aria-label="Закрыть панель">
            <i class="ti ti-x"></i>
        </button>
    </header>

    <div class="ai-panel__messages" id="ai-messages" role="log" aria-live="polite" aria-busy="false">
        <div class="ai-msg ai-msg--ai">
            <div class="ai-msg__avatar"><i class="ti ti-sparkles"></i></div>
            <div class="ai-msg__bubble">
                Здравствуйте! Я AI-ассистент CorpMS: строю отчёты и диаграммы прямо в чате,
                ищу по базе и подсказываю, куда перейти — только по данным, доступным вашему scope.
            </div>
        </div>

        <div class="ai-chips" id="ai-chips">
            <button type="button" class="crm-ai__chip" data-ai-chip="Покажи посещаемость сегодня">
                <i class="ti ti-clock me-1"></i>Посещаемость сегодня
            </button>
            <button type="button" class="crm-ai__chip" data-ai-chip="Сколько задач в работе?">
                <i class="ti ti-checkbox me-1"></i>Задачи в работе
            </button>
            <button type="button" class="crm-ai__chip" data-ai-chip="Построй отчёт по задачам">
                <i class="ti ti-chart-bar me-1"></i>Отчёт по задачам
            </button>
            <button type="button" class="crm-ai__chip" data-ai-chip="Найди: Иванова">
                <i class="ti ti-search me-1"></i>Найти сотрудника
            </button>
            <button type="button" class="crm-ai__chip" data-ai-chip="Численность по отделам">
                <i class="ti ti-users me-1"></i>Численность по отделам
            </button>
            <button type="button" class="crm-ai__chip" data-ai-chip="На что обратить внимание?">
                <i class="ti ti-bulb me-1"></i>Инсайты
            </button>
            <button type="button" class="crm-ai__chip" data-ai-chip="Построй сводный отчёт">
                <i class="ti ti-report me-1"></i>Сводный отчёт
            </button>
            <button type="button" class="crm-ai__chip" data-ai-chip="Запомни: мой проект — Альфа">
                <i class="ti ti-brain me-1"></i>Запомнить
            </button>
        </div>

        <div class="ai-typing" id="ai-typing" hidden role="status" aria-label="Ассистент печатает">
            <span></span><span></span><span></span>
        </div>
    </div>

    <form class="ai-panel__composer" id="ai-form" method="post" action="{{ route('ai.message') }}" autocomplete="off">
        @csrf
        <input type="text" id="ai-input" name="question" class="form-control ai-panel__input"
               minlength="3" maxlength="500" required
               placeholder="Спросите или дайте задачу…"
               aria-label="Вопрос ассистенту">
        <button type="submit" class="btn btn-primary ai-panel__send" id="ai-send" aria-label="Отправить">
            <i class="ti ti-send"></i>
        </button>
    </form>
</aside>
