<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * UX-01…UX-05 / FE-01…FE-05: статические DOM-/JS-инварианты.
 *
 * Node/npm в окружении недоступны — поведенческий браузерный smoke (фокус,
 * стрелки, интервалы polling) не выполнялся; тест защищает от регрессии
 * контракта, который проверен в коде.
 */
final class FrontendAccessibilityTest extends TestCase
{
    private function source(string $relative): string
    {
        $path = base_path($relative);
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function test_command_palette_traps_focus_and_marks_background_inert(): void
    {
        $js = $this->source('public/assets/js/corpms.js');

        self::assertStringContainsString('function trapTab(event)', $js);
        self::assertStringContainsString("if (event.key === 'Tab') { trapTab(event); }", $js);
        self::assertStringContainsString('function setBackgroundInert(on)', $js);
        self::assertStringContainsString('setBackgroundInert(true)', $js);
        self::assertStringContainsString('setBackgroundInert(false)', $js);

        $layout = $this->source('resources/views/layouts/tabler.blade.php');
        self::assertStringContainsString('data-crm-palette-background', $layout);
        self::assertStringContainsString('role="dialog" aria-modal="true"', $layout);
        self::assertStringContainsString('aria-label="{{ __("Поиск команд и разделов") }}" role="combobox"', $layout);
    }

    public function test_search_results_expose_selection_to_screen_readers(): void
    {
        $navbar = $this->source('resources/views/partials/navbar.blade.php');
        self::assertStringContainsString('role="combobox"', $navbar);
        self::assertStringContainsString('role="listbox"', $navbar);

        $js = $this->source('public/assets/js/corpms.js');
        self::assertStringContainsString("role=\"option\" id=\"crm-search-option-' + index + '\" aria-selected=\"false\"", $js);
        self::assertStringContainsString("el.setAttribute('aria-selected', i === active ? 'true' : 'false');", $js);
        self::assertStringContainsString("input.setAttribute('aria-activedescendant', list[active].id);", $js);
    }

    public function test_search_failures_are_visible_instead_of_silently_hidden(): void
    {
        $js = $this->source('public/assets/js/corpms.js');

        self::assertStringContainsString('function showSearchError(status, requestSeq)', $js);
        self::assertStringContainsString('role="alert"', $js);
        self::assertStringContainsString('showSearchError(res.status, mySeq)', $js);
        self::assertStringContainsString('showSearchError(0, mySeq)', $js);
        self::assertStringContainsString('status === 429', $js);

        $css = $this->source('public/assets/css/effects.css');
        self::assertStringContainsString('.crm-search-error', $css);
    }

    public function test_chat_poll_backoff_is_not_overwritten(): void
    {
        $js = $this->source('public/assets/js/bm-chat.js');

        self::assertStringContainsString('var nextDelay = 4000;', $js);
        self::assertStringContainsString('nextDelay = Math.min(30000, 4000 * Math.pow(2, Math.min(consecutiveFailures, 3)));', $js);
        self::assertStringContainsString('schedulePoll(nextDelay);', $js);
        self::assertStringNotContainsString('                schedulePoll(Math.min(30000, 4000', $js);
    }

    public function test_chat_edit_keeps_draft_when_polling_updates_message(): void
    {
        $js = $this->source('public/assets/js/bm-chat.js');

        self::assertStringContainsString('var editingIds = {};', $js);
        self::assertStringContainsString('if (body && ! editingIds[message.id])', $js);
        self::assertStringContainsString('editingIds[id] = true;', $js);
        self::assertStringContainsString('if (finished || saving) { return; }', $js);
        self::assertStringContainsString('finishFailed(value, err)', $js);
    }

    /** UX-06: все LMS-опросы останавливаются на скрытой секции/вкладке. */
    public function test_lms_polling_has_visibility_lifecycle(): void
    {
        foreach (['lms.js', 'lms-whiteboard.js', 'lms-pulse.js'] as $file) {
            $js = $this->source('public/assets/js/'.$file);
            self::assertStringContainsString("document.addEventListener('visibilitychange', syncPolling);", $js, $file);
            self::assertStringContainsString("document.addEventListener('lms:sectionchange', syncPolling);", $js, $file);
            self::assertStringContainsString('clearInterval(', $js, $file);
        }

        self::assertStringContainsString(
            "document.dispatchEvent(new CustomEvent('lms:sectionchange', { detail: { section: name } }));",
            $this->source('public/assets/js/lms.js'),
        );
    }

    /** UX-07: повторная инициализация TinyMCE снимает прежние подписки. */
    public function test_rich_editor_handlers_are_detached_on_reinit(): void
    {
        $js = $this->source('public/assets/js/corpms.js');

        self::assertStringContainsString('var richHandlers = new WeakMap();', $js);
        self::assertStringContainsString('function detachRichEditor(textarea)', $js);
        self::assertStringContainsString('stored.form.removeEventListener(\'submit\', stored.submit, true)', $js);
        self::assertStringContainsString('stored.modal.removeEventListener(\'shown.bs.modal\', stored.shown)', $js);
        self::assertStringContainsString('detachRichEditor(textarea);', $js);
    }

    /** UX-08…UX-13: доступные элементы управления и связи label/control. */
    public function test_navigation_and_form_controls_are_accessible(): void
    {
        $layout = $this->source('resources/views/layouts/tabler.blade.php');
        self::assertStringNotContainsString('<a class="btn-close"', $layout);
        self::assertSame(2, substr_count($layout, '<button type="button" class="btn-close" data-bs-dismiss="alert"'));

        $actions = $this->source('resources/views/partials/nav-actions.blade.php');
        self::assertStringContainsString('data-bs-target="#crm-bell" aria-controls="crm-bell" aria-label=', $actions);
        self::assertStringNotContainsString('href="#"', $actions);
        self::assertStringContainsString('aria-label="{{ __("Меню пользователя") }}"', $actions);
        self::assertStringContainsString('aria-label="{{ __("Язык интерфейса") }}"', $actions);
        self::assertStringContainsString('aria-label="{{ __("Поиск по системе (Ctrl+K)") }}"', $actions);

        $onboarding = $this->source('resources/views/onboarding/index.blade.php');
        self::assertStringContainsString('for="onb-name"', $onboarding);
        self::assertStringContainsString('id="onb-name"', $onboarding);
        $imports = $this->source('resources/views/imports/index.blade.php');
        self::assertStringContainsString('for="imp-type"', $imports);
        self::assertStringContainsString('id="imp-type"', $imports);
        self::assertStringContainsString('for="imp-file"', $imports);

        $accounting = $this->source('resources/views/accounting/documents/index.blade.php');
        self::assertStringContainsString('<label class="h3" for="ad-notes">', $accounting);
    }

    /** UX-14/UX-15/UX-18/UX-19: таксономия, иконки из ITEMS, токены вместо inline-стилей. */
    public function test_navigation_taxonomy_and_responsive_tokens(): void
    {
        $sidebar = $this->source('app/Support/Sidebar.php');
        self::assertStringContainsString("['_', 'Склад и закупки', 'package']", $sidebar);
        self::assertStringContainsString("'Денежные операции'", $sidebar);
        self::assertStringContainsString("'Бухгалтерские отчёты'", $sidebar);
        self::assertStringContainsString("'icon' => \$sectionIcon", $sidebar);

        $blade = $this->source('resources/views\partials\sidebar.blade.php');
        self::assertStringContainsString("\$group['icon']", $blade);
        self::assertStringNotContainsString('$icons = [', $blade);

        self::assertStringNotContainsString('style="width: 340px"', $this->source('resources/views/admin/users.blade.php'));
        self::assertStringNotContainsString('style="min-height: 168px"', $this->source('resources/views/analytics/index.blade.php'));
        self::assertStringNotContainsString('style="font-size: 1.2rem', $this->source('resources/views/bi/index.blade.php'));
        self::assertStringContainsString('.crm-search-page-input', $this->source('public/assets/css/effects.css'));

        foreach (['auth/login.blade.php', 'signatures/qr-sign.blade.php', 'search/index.blade.php'] as $view) {
            self::assertStringNotContainsString('autofocus', $this->source('resources/views/'.$view), $view);
        }
    }

    /** UX-17: ошибки LMS-pulse видны и есть кнопка повтора. */
    public function test_lms_pulse_reports_sync_failures(): void
    {
        $partial = $this->source('resources/views/lms/partials/pulse-feed.blade.php');
        self::assertStringContainsString('data-lms-pulse-status', $partial);
        self::assertStringContainsString('data-lms-pulse-retry', $partial);

        $js = $this->source('public/assets/js/lms-pulse.js');
        self::assertStringContainsString('function setSyncState(ok)', $js);
        self::assertStringContainsString('setSyncState(false)', $js);
        self::assertStringContainsString("retry.addEventListener('click', poll)", $js);
    }

    /** UX-20: длинные списки отдают постранично. */
    public function test_long_lists_are_paginated(): void
    {
        self::assertStringContainsString('->paginate(50)->withQueryString()', $this->source('app/Http/Controllers/Web/AttendanceController.php'));
        $payroll = $this->source('app/Http/Controllers/Web/PayrollController.php');
        self::assertStringContainsString('->paginate(30)', $payroll);
        self::assertStringContainsString('->withQueryString()', $payroll);
        $rfid = $this->source('app/Http/Controllers/Web/RfidController.php');
        self::assertStringContainsString("paginate(50, ['*'], 'cards_page')", $rfid);
        self::assertStringContainsString("paginate(50, ['*'], 'events_page')", $rfid);

        self::assertStringContainsString('{{ $anomalies->links() }}', $this->source('resources/views/attendance/anomalies.blade.php'));
        self::assertStringContainsString('{{ $periods->links() }}', $this->source('resources/views/payroll/index.blade.php'));
        $rfidView = $this->source('resources/views/biometrics/rfid.blade.php');
        self::assertStringContainsString('{{ $cards->links() }}', $rfidView);
        self::assertStringContainsString('{{ $events->links() }}', $rfidView);
    }

    /** UX-21: выпадающий crm-select не уходит под следующую секцию формы. */
    public function test_crm_select_menu_is_above_following_sections(): void
    {
        $css = $this->source('public/assets/css/crm-select.css');
        self::assertStringContainsString('.crm-select.is-open { z-index: 60; }', $css);
        self::assertStringContainsString('.crm-form__section:has(.crm-select.is-open)', $css);
        self::assertStringContainsString('details:has(.crm-select.is-open)::details-content { overflow: visible; }', $css);
        self::assertStringContainsString('z-index: 1080;', $css);
    }

    /** UX-22: список Select2 в модалке не обрезается overflow модалки. */
    public function test_modal_select2_dropdown_lives_in_body(): void
    {
        $js = $this->source('public/assets/js/corpms.js');
        self::assertStringContainsString('dropdownParent: modal ? $(document.body) : null,', $js);
        self::assertStringNotContainsString('dropdownParent: modal ? $(modal) : null,', $js);

        $css = $this->source('public/assets/css/effects.css');
        self::assertStringContainsString('.select2-container--open { z-index: 2000; }', $css);
    }

    /** UX-23: сообщения чата LMS читаемы в светлой и тёмной теме. */
    public function test_lms_chat_contrast_uses_theme_tokens(): void
    {
        $css = $this->source('public/assets/css/lms.css');
        self::assertStringNotContainsString('var(--crm-bg-subtle, #f1f5f9)', $css);
        self::assertStringNotContainsString('var(--crm-primary-bg, rgba(32, 107, 196, .12))', $css);
        self::assertStringContainsString('background: var(--crm-surface-raised, var(--crm-surface-solid));', $css);
        self::assertStringContainsString('background: rgba(var(--crm-accent-rgb), .14);', $css);
        self::assertStringContainsString('color: var(--crm-text);', $css);
    }

    /** UX-24: подписи кнопок в шапке чата видны без наведения. */
    public function test_chat_header_actions_have_visible_labels(): void
    {
        $view = $this->source('resources/views/chat/index.blade.php');
        self::assertStringContainsString('bm-chat__header-action-label', $view);

        $css = $this->source('public/assets/css/bm-chat.css');
        self::assertStringContainsString('.bm-chat__header-action-label { white-space: nowrap; }', $css);
    }

    /** UX-25: служебные коды статусов и действий журнала локализованы. */
    public function test_status_and_audit_codes_are_localized(): void
    {
        $task = $this->source('resources/views/tasks/show.blade.php');
        self::assertStringContainsString("\$crm_label('task_status', \$history->from_status)", $task);
        self::assertStringContainsString("\$crm_label('task_status', \$history->to_status)", $task);

        $analytics = $this->source('resources/views/analytics/index.blade.php');
        self::assertStringContainsString("\$crm_label('audit_action', \$item['action'])", $analytics);

        $labels = $this->source('app/Support/EnumLabels.php');
        self::assertStringContainsString("'auth.login' => 'Вход в систему'", $labels);
        self::assertStringContainsString("'task.status_changed' => 'Изменение статуса задачи'", $labels);
        self::assertStringContainsString("'ai.asked' => 'Запрос к ИИ'", $labels);
        self::assertStringContainsString("'report.exported' => 'Экспорт отчёта'", $labels);
    }
}
