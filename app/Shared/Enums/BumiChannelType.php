<?php

declare(strict_types=1);

namespace App\Shared\Enums;

/**
 * Внешние каналы, из которых клиенты пишут Bumi.
 *
 * Это именно каналы обращений, а не внутренний чат сотрудников и не
 * уведомления: каждый канал привязан к агенту, компании и лиду CRM.
 */
enum BumiChannelType: string
{
    case Website = 'website';
    case Telegram = 'telegram';
    case WhatsApp = 'whatsapp';
    case Instagram = 'instagram';
    case GreenApi = 'greenapi';

    public function label(): string
    {
        return match ($this) {
            self::Website => __('Чат на сайте'),
            self::Telegram => __('Telegram'),
            self::WhatsApp => __('WhatsApp Cloud'),
            self::Instagram => __('Instagram Graph'),
            self::GreenApi => __('WhatsApp Green API'),
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Website => 'world',
            self::Telegram => 'brand-telegram',
            self::WhatsApp => 'brand-whatsapp',
            self::Instagram => 'brand-instagram',
            self::GreenApi => 'message-circle',
        };
    }

    /** Источник лида CRM, под которым сохраняются обращения канала. */
    public function leadSource(): CrmLeadSource
    {
        return match ($this) {
            self::Website => CrmLeadSource::Web,
            self::Telegram => CrmLeadSource::Telegram,
            self::WhatsApp => CrmLeadSource::WhatsApp,
            self::Instagram => CrmLeadSource::Instagram,
            self::GreenApi => CrmLeadSource::WhatsApp,
        };
    }

    /** Поля учётных данных, редактируемые на экране канала. */
    public function secretFields(): array
    {
        return match ($this) {
            self::Website => [],
            self::Telegram => ['bot_token', 'webhook_secret'],
            self::WhatsApp => ['app_secret', 'access_token', 'phone_number_id', 'verify_token'],
            self::Instagram => ['app_secret', 'access_token', 'ig_user_id', 'verify_token'],
            self::GreenApi => ['instance_id', 'api_token', 'webhook_secret'],
        };
    }

    /** Поля, которые можно показать администратору открытым текстом. */
    public function publicFields(): array
    {
        return match ($this) {
            self::Website => [],
            self::Telegram => [],
            self::WhatsApp => ['phone_number_id', 'verify_token'],
            self::Instagram => ['ig_user_id', 'verify_token'],
            self::GreenApi => ['instance_id'],
        };
    }
}
