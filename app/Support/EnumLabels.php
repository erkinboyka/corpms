<?php

declare(strict_types=1);

namespace App\Support;

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
        'device_type' => [
            'face_terminal' => 'Терминал лица', 'kiosk' => 'Киоск',
            'fingerprint_gate' => 'Дактило-турникет', 'turnstile' => 'Турникет',
            'qr_reader' => 'QR-считыватель',
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
    ];

    public static function label(string $group, ?string $value, ?string $fallback = null): string
    {
        if ($value === null) {
            return '—';
        }

        return self::MAPS[$group][$value] ?? ($fallback ?? $value);
    }
}
