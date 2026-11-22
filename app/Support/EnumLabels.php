<?php

declare(strict_types=1);

namespace App\Support;

use App\Shared\Enums\BiometricDeviceType;

/**
 * Русские подписи enum-ов для UI. Единственный источник подписей:
 * шаблоны вызывают {{ crm_label('task_status', $task->status->value) }}.
 */
final class EnumLabels
{
    /** @var array<string, array<string, string>> */
    private const MAPS = [
        'task_status' => [
            'new' => 'Новая', 'planned' => 'Запланирована', 'in_progress' => 'В работе',
            'review' => 'На проверке', 'returned' => 'Возвращена', 'completed' => 'Завершена',
            'cancelled' => 'Отменена', 'overdue' => 'Просрочена',
        ],
        'task_assignment_status' => [
            'pending' => 'Ожидает согласия', 'accepted' => 'Принята', 'in_progress' => 'В работе',
            'review' => 'На проверке', 'completed' => 'Завершена', 'returned' => 'Возвращена',
            'rejected' => 'Отклонена',
        ],
        'task_priority' => [
            'low' => 'Низкий', 'medium' => 'Средний', 'high' => 'Высокий', 'critical' => 'Критичный',
        ],
        'task_type' => [
            'task' => 'Задача', 'order' => 'Поручение', 'document_task' => 'Задача по документу',
            'approval_task' => 'Согласование', 'document_prep' => 'Подготовка документа',
            'document_sign' => 'Подписание', 'document_review' => 'Проверка документа',
            'service' => 'Служебная',
        ],
        'employee_status' => [
            'active' => 'Работает', 'inactive' => 'Не активен', 'dismissed' => 'Уволен',
        ],
        'attendance_status' => [
            'on_time' => 'Вовремя', 'late' => 'Опоздание', 'absent' => 'Отсутствие',
            'early_leave' => 'Ранний уход', 'overtime' => 'Переработка', 'present' => 'На месте',
            'weekend' => 'Выходной', 'holiday' => 'Праздник',
        ],
        'attendance_source' => [
            'biometric' => 'Биометрия', 'qr' => 'QR', 'kiosk' => 'Терминал', 'manual' => 'Вручную',
        ],
        'document_status' => [
            'draft' => 'Черновик', 'submitted' => 'Подан', 'review' => 'На согласовании',
            'revision' => 'На доработке', 'approved' => 'Согласован', 'signing' => 'На подписании',
            'signed' => 'Подписан', 'registered' => 'Зарегистрирован', 'archived' => 'В архиве',
            'rejected' => 'Отклонён',
        ],
        'document_priority' => [
            'low' => 'Низкий', 'medium' => 'Средний', 'high' => 'Высокий', 'critical' => 'Критический',
        ],
        'document_type' => [
            'incoming' => 'Входящий', 'outgoing' => 'Исходящий', 'internal' => 'Внутренний',
        ],
        'confidentiality' => [
            'public' => 'Публичный', 'internal' => 'Для сотрудников', 'confidential' => 'Конфиденциальный',
            'secret' => 'Секретный',
        ],
        'approval_status' => [
            'pending' => 'Ожидает', 'approved' => 'Согласовано', 'rejected' => 'Отклонено',
            'revision_requested' => 'Запрошена доработка', 'skipped' => 'Пропущено',
        ],
        'leave_status' => [
            'pending' => 'Ожидает', 'approved' => 'Одобрена', 'rejected' => 'Отклонена',
            'cancelled' => 'Отменена',
        ],
        'leave_type' => [
            'vacation' => 'Отпуск', 'sick' => 'Больничный', 'business_leave' => 'Командировка',
            'day_off' => 'Отгул', 'other' => 'Другое',
        ],
        'correction_status' => [
            'pending' => 'Ожидает', 'approved' => 'Одобрена', 'rejected' => 'Отклонена',
        ],
        'anomaly_status' => [
            'open' => 'Открыта', 'resolved' => 'Решена', 'ignored' => 'Проигнорирована',
        ],
        'anomaly_type' => [
            'missing_checkout' => 'Нет отметки выхода', 'no_show' => 'Нет явки',
            'duplicate' => 'Дубль', 'direction_mismatch' => 'Неверное направление',
            'unrecognized' => 'Не распознано',
        ],
        'device_status' => [
            'online' => 'Онлайн', 'offline' => 'Офлайн', 'disabled' => 'Отключён',
        ],
        'device_direction' => [
            'in' => 'Вход', 'out' => 'Выход', 'both' => 'Вход/выход',
        ],
        'biometric_profile_status' => [
            'pending' => 'Ожидает регистрации', 'enrolled' => 'Зарегистрирован',
            'disabled' => 'Отключён', 'deleted' => 'Удалён',
        ],
        'biometric_event_status' => [
            'pending' => 'В обработке', 'processed' => 'Обработано', 'failed' => 'Ошибка',
            'skipped' => 'Пропущено',
        ],
        'signature_request_status' => [
            'pending' => 'Ожидает подписи', 'awaiting_confirmation' => 'Ожидает подтверждения',
            'signed' => 'Подписано', 'declined' => 'Отказ', 'revision' => 'Доработка',
            'cancelled' => 'Отменено', 'partial' => 'Частично подписано',
        ],
        'signature_status' => [
            'valid' => 'Действует', 'revoked' => 'Отозвана', 'invalid' => 'Недействительна',
        ],
        'rfid_card_status' => [
            'active' => 'Активна', 'disabled' => 'Отозвана',
        ],
        'qr_status' => [
            'active' => 'Активен', 'revoked' => 'Отозван', 'blocked' => 'Остановлен',
        ],
        'workflow_state' => [
            'not_started' => 'Не запущен', 'in_progress' => 'Выполняется',
            'completed' => 'Завершён', 'failed' => 'Прерван',
        ],
        'workflow_action' => [
            'request' => 'Создана заявка',
            'submit' => 'Отправлено на согласование',
            'approve_step' => 'Шаг согласован',
            'approve_final' => 'Согласовано',
            'approve' => 'Согласовано',
            'reject' => 'Отклонено',
            'register' => 'Зарегистрировано',
            'archive' => 'В архив',
            'request_signatures' => 'Запрошены подписи',
            'quick_qr_signature' => 'Быстрая подпись по QR',
            'sign' => 'Подписано',
            'decline' => 'Подпись отклонена',
            'calculate' => 'Рассчитано',
            'pay' => 'Выплачено',
        ],
        'schedule_type' => [
            'standard' => 'Стандартный', 'flexible' => 'Гибкий', 'shift' => 'Сменный',
            'custom' => 'Особый',
        ],
        'notification_channel' => [
            'app' => 'Приложение', 'telegram' => 'Telegram', 'email' => 'Email',
        ],
        'holiday_kind' => [
            'public' => 'Нерабочий', 'operating' => 'Рабочий', 'shortened' => 'Сокращённый',
        ],
        'payroll_status' => [
            'draft' => 'Черновик', 'calculated' => 'Рассчитан',
            'approved' => 'Согласован', 'paid' => 'Выплачен',
        ],
        'payroll_component_kind' => [
            'earning' => 'Начисление', 'deduction' => 'Удержание',
        ],
        'payroll_calculation' => [
            'fixed' => 'Фиксированная сумма', 'hourly' => 'Почасовая',
            'overtime' => 'Сверхурочные', 'percent' => 'Процент от оклада',
            'manual' => 'Ручная сумма',
        ],
        'payroll_line_source' => [
            'auto' => 'Расчёт', 'manual' => 'Вручную',
        ],
        'product_kind' => [
            'goods' => 'Товар', 'service' => 'Услуга',
        ],
        'stock_movement_type' => [
            'in' => 'Приход', 'out' => 'Расход',
            'adjustment' => 'Корректировка', 'write_off' => 'Списание',
        ],
        'purchase_order_status' => [
            'draft' => 'Черновик', 'ordered' => 'Размещён',
            'received' => 'Принят', 'cancelled' => 'Отменён',
        ],
        'crm_client_kind' => [
            'company' => 'Организация', 'person' => 'Частное лицо',
        ],
        'crm_client_status' => [
            'active' => 'Активен', 'archived' => 'В архиве',
        ],
        'crm_lead_source' => [
            'web' => 'Сайт', 'call' => 'Звонок', 'referral' => 'Рекомендация',
            'event' => 'Мероприятие', 'other' => 'Другое',
        ],
        'crm_lead_status' => [
            'new' => 'Новый', 'qualified' => 'Квалифицирован',
            'converted' => 'Конвертирован', 'lost' => 'Потерян',
        ],
        'crm_deal_stage' => [
            'new' => 'Новая', 'qualification' => 'Квалификация',
            'proposal' => 'Предложение', 'negotiation' => 'Переговоры',
            'won' => 'Выиграна', 'lost' => 'Проиграна',
        ],
        'crm_activity_kind' => [
            'call' => 'Звонок', 'meeting' => 'Встреча', 'email' => 'Письмо',
            'task' => 'Задача', 'note' => 'Заметка',
        ],
        'fin_account_kind' => [
            'cash' => 'Касса', 'bank' => 'Расчётный счёт',
        ],
        'fin_direction' => [
            'income' => 'Доход', 'expense' => 'Расход',
        ],
        'fin_invoice_kind' => [
            'invoice' => 'Счёт', 'act' => 'Акт',
        ],
        'fin_invoice_status' => [
            'draft' => 'Черновик', 'issued' => 'Выставлен',
            'paid' => 'Оплачен', 'cancelled' => 'Отменён',
        ],
        'fin_payment_method' => [
            'cash' => 'Наличные', 'bank_transfer' => 'Перевод',
            'card' => 'Карта', 'other' => 'Другое',
        ],
        'fin_transaction_source' => [
            'manual' => 'Ручной ввод', 'invoice_payment' => 'Оплата счёта',
        ],
        'acc_account_kind' => [
            'asset' => 'Актив', 'liability' => 'Пассив', 'equity' => 'Капитал',
            'income' => 'Доход', 'expense' => 'Расход',
        ],
        'acc_entry_source' => [
            'manual' => 'Ручная', 'finance' => 'Из финансов',
        ],
        'acc_document_kind' => [
            'invoice' => 'Счёт', 'act' => 'Акт',
            'payment_order' => 'Платёжное поручение', 'other' => 'Другое',
        ],
        'project_status' => [
            'planned' => 'Запланирован', 'active' => 'В работе', 'on_hold' => 'Приостановлен',
            'completed' => 'Завершён', 'cancelled' => 'Отменён',
        ],
        'project_member_role' => [
            'manager' => 'Руководитель', 'member' => 'Участник', 'observer' => 'Наблюдатель',
        ],
        'bi_widget_kind' => [
            'kpi' => 'KPI', 'trend' => 'Тренд',
        ],

        // --- Обучение (LMS) ---
        'lms_course_status' => [
            'draft' => 'Черновик', 'published' => 'Опубликован', 'archived' => 'В архиве',
        ],
        'lms_enrollment_status' => [
            'enrolled' => 'Записан', 'in_progress' => 'В процессе', 'completed' => 'Завершён',
        ],
        'lms_progress_status' => [
            'not_started' => 'Не начат', 'in_progress' => 'В работе', 'completed' => 'Пройден',
        ],
        'lms_assignment_status' => [
            'draft' => 'Черновик', 'open' => 'Открыто', 'closed' => 'Закрыто',
        ],
        'lms_submission_status' => [
            'draft' => 'Черновик', 'submitted' => 'Сдано', 'reviewed' => 'Проверено', 'returned' => 'Возвращено',
        ],
        'lms_meeting_status' => [
            'scheduled' => 'Запланирован', 'live' => 'Идёт', 'ended' => 'Завершён',
        ],
        'lms_element_type' => [
            'path' => 'Линия', 'rect' => 'Прямоугольник', 'ellipse' => 'Круг',
            'text' => 'Текст', 'sticky' => 'Заметка',
        ],
    ];

    /** Коды действий журнала, которые выводятся на дашборде аналитики. */
    private const AUDIT_ACTIONS = [
        'auth.login' => 'Вход в систему',
        'auth.logout' => 'Выход из системы',
        'auth.failed' => 'Неудачный вход',
        'auth.password_reset_self' => 'Самостоятельная смена пароля',
        'task.created' => 'Создание задачи',
        'task.status_changed' => 'Изменение статуса задачи',
        'task.assigned' => 'Назначение задачи',
        'task.completed' => 'Завершение задачи',
        'ai.asked' => 'Запрос к ИИ',
        'ai.retried' => 'Повторный запрос к ИИ',
        'ai.tool.called' => 'Вызов инструмента ИИ',
        'report.exported' => 'Экспорт отчёта',
        'signature.qr_prepared' => 'Подготовка QR-подписи',
        'signature.signed' => 'Подписание документа',
        'signature.requested' => 'Запрос подписи',
        'signature.otp_failed' => 'Неудачная проверка OTP',
        'signature.otp_blocked' => 'Блокировка OTP',
        'signature.declined' => 'Отказ от подписи',
        'signature.revoked' => 'Отзыв подписи',
        'signature.tampered' => 'Нарушение целостности подписи',
        'document.created' => 'Создание документа',
        'document.submitted' => 'Отправка документа',
        'document.registered' => 'Регистрация документа',
        'document.version_added' => 'Добавление версии документа',
        'chat.message.sent' => 'Отправка сообщения',
        'chat.message.edited' => 'Редактирование сообщения',
        'chat.message.deleted' => 'Удаление сообщения',
        'lms.enrolled' => 'Запись на курс',
        'lms.lesson.completed' => 'Прохождение урока',
        'lms.assignment.submitted' => 'Сдача задания',
        'attendance.correction.requested' => 'Запрос корректировки посещаемости',
        'attendance.correction.approved' => 'Одобрение корректировки посещаемости',
        'attendance.correction.rejected' => 'Отклонение корректировки посещаемости',
        'backup.created' => 'Создание резервной копии',
        'backup.downloaded' => 'Скачивание резервной копии',
        'bi.rebuilt' => 'Перестроение BI-витрины',
    ];

    /** @var array<string, string> */
    private const AUDIT_SEGMENTS = [
        'accounting' => 'Бухгалтерия', 'activity' => 'Активность', 'added' => 'Добавление',
        'approved' => 'Одобрение', 'archived' => 'Архивация', 'assignment' => 'Задание',
        'auth' => 'Авторизация', 'backup' => 'Резервная копия', 'bi' => 'BI',
        'biometry' => 'Биометрия', 'blocked' => 'Блокировка', 'called' => 'Вызов',
        'changed' => 'Изменение', 'channel' => 'Канал', 'chart' => 'План счетов',
        'client' => 'Клиент', 'company' => 'Компания', 'conversation' => 'Диалог',
        'course' => 'Курс', 'created' => 'Создание', 'crm' => 'CRM', 'declined' => 'Отказ',
        'deleted' => 'Удаление', 'department' => 'Отдел', 'device' => 'Устройство',
        'document' => 'Документ', 'edited' => 'Редактирование', 'employee' => 'Сотрудник',
        'enabled' => 'Включение', 'entry' => 'Проводка', 'exported' => 'Экспорт',
        'failed' => 'Ошибка', 'final' => 'Итоговый', 'finance' => 'Финансы',
        'generated' => 'Генерация', 'google' => 'Google', 'group' => 'Группа',
        'import' => 'Импорт', 'lesson' => 'Урок', 'login' => 'Вход', 'logout' => 'Выход',
        'meeting' => 'Созвон', 'member' => 'Участник', 'members' => 'Участники',
        'message' => 'Сообщение', 'moved' => 'Перемещение', 'notification' => 'Уведомление',
        'oauth' => 'OAuth', 'otp' => 'OTP', 'password' => 'Пароль', 'position' => 'Должность',
        'prepared' => 'Подготовка', 'privacy' => 'Конфиденциальность', 'qr' => 'QR',
        'registered' => 'Регистрация', 'rejected' => 'Отклонение', 'removed' => 'Удаление',
        'reset' => 'Сброс', 'restored' => 'Восстановление', 'reviewed' => 'Проверка',
        'revoked' => 'Отзыв', 'role' => 'Роль', 'schedule' => 'Расписание',
        'self' => 'Самостоятельно', 'sent' => 'Отправка', 'setting' => 'Настройка',
        'settings' => 'Настройки', 'signed' => 'Подписание', 'signature' => 'Подпись',
        'status' => 'Статус', 'step' => 'Этап', 'submitted' => 'Отправка',
        'synced' => 'Синхронизация', 'tampered' => 'Подмена', 'task' => 'Задача',
        'template' => 'Шаблон', 'tool' => 'Инструмент', 'totp' => 'TOTP',
        'updated' => 'Обновление', 'user' => 'Пользователь', 'verified' => 'Подтверждение',
        'version' => 'Версия', 'ai' => 'ИИ', 'lms' => 'Обучение', 'report' => 'Отчёт',
        'attendance' => 'Посещаемость', 'correction' => 'Корректировка', 'training' => 'Обучение',
    ];

    public static function label(string $group, ?string $value, ?string $fallback = null): string
    {
        if ($value === null) {
            return '—';
        }

        if ($group === 'device_type') {
            $type = BiometricDeviceType::tryFrom($value);

            return $type?->label() ?? ($fallback ?? $value);
        }

        if ($group === 'audit_action' && isset(self::AUDIT_ACTIONS[$value])) {
            return self::AUDIT_ACTIONS[$value];
        }

        if ($group === 'audit_action') {
            $parts = array_filter(explode('.', $value), static fn (string $part): bool => $part !== '');
            $words = [];
            foreach ($parts as $part) {
                $words[] = self::AUDIT_SEGMENTS[$part] ?? str_replace('_', ' ', $part);
            }

            return $words === [] ? $value : implode(' · ', $words);
        }

        return self::MAPS[$group][$value] ?? ($fallback ?? $value);
    }
}
