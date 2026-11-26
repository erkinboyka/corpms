<?php

declare(strict_types=1);

namespace App\Domains\Ai\Services;

use App\Domains\Ai\DTO\BumiPersona;
use App\Models\BumiAgent;
use App\Shared\Enums\BumiMood;

/**
 * Серверная логика настроения Bumi.
 *
 * Решение принимается из реальных признаков запроса: flash-сообщений,
 * ошибок валидации, маршрута и времени суток. Клиентский JS продолжает
 * реакцию на события страницы, но стартовое состояние всегда осмысленное.
 */
class BumiPersonaService
{
    public function current(): BumiPersona
    {
        $request = request();
        $session = $request->hasSession() ? $request->getSession() : null;
        $errors = $session?->get('errors');
        $error = (string) ($session?->get('error') ?? '');
        $warning = (string) ($session?->get('warning') ?? '');
        $status = (string) ($session?->get('status') ?? '');
        $route = (string) ($request->route()?->getName() ?? '');

        if ($errors !== null && method_exists($errors, 'any') && $errors->any()) {
            return BumiPersona::make(
                BumiMood::Sad,
                'validation',
                __('Стоп. Одно поле просит внимания — я уже подсветил.'),
            );
        }

        if ($error !== '') {
            return $this->errorPersona($error);
        }

        if ($status !== '') {
            return BumiPersona::make(
                BumiMood::Proud,
                'success',
                __('Готово. Я всё зафиксировал — можешь не перепроверять.'),
            );
        }

        if ($warning !== '') {
            return BumiPersona::make(
                BumiMood::Sluggish,
                'warning',
                __('Принял. Но тут есть нюанс — давай глянем ещё раз.'),
            );
        }

        if (str_starts_with($route, 'bi.')) {
            return BumiPersona::make(
                BumiMood::Thinking,
                'bi',
                __('Витрина на месте. Скажи, что пересчитать — сделаю тихо и быстро.'),
            );
        }

        if ($route === 'ai.index') {
            return BumiPersona::make(
                BumiMood::Thinking,
                'chat',
                __('Я в рабочем чате. Дай задачу — разберусь по фактам.'),
            );
        }

        if ($route === 'bumi.settings.agent') {
            $agent = $request->route('agent');
            if ($agent instanceof BumiAgent && ! $agent->enabled) {
                return BumiPersona::make(
                    BumiMood::Sleepy,
                    'agent_paused',
                    __('Я на паузе. Включи — вернусь с идеями.'),
                );
            }
        }

        if (str_starts_with($route, 'settings.bumi') || str_starts_with($route, 'bumi.settings.')) {
            return BumiPersona::make(
                BumiMood::Curious,
                'settings',
                __('Здесь меня настраивают. Осторожно: я записываю всё.'),
            );
        }

        if ($route === 'login' || str_starts_with($route, 'password.')) {
            return BumiPersona::make(
                BumiMood::Wink,
                'auth',
                __('С возвращением. Давай быстренько по делам.'),
            );
        }

        $hour = (int) now()->format('H');
        if ($hour < 7 || $hour >= 22) {
            return BumiPersona::make(
                BumiMood::Sleepy,
                'after_hours',
                __('Ззз… Часы поздние. Давай по делу — и спать.'),
            );
        }

        if ($hour < 10) {
            return BumiPersona::make(
                BumiMood::Sluggish,
                'morning',
                __('Кофе ещё не подействовал? У меня тоже. Начнём с малого.'),
            );
        }

        return BumiPersona::make(
            BumiMood::Happy,
            'ready',
            __('Я на связи. Скажи, что нужно — разберусь.'),
        );
    }

    /**
     * Право отказа и системные сбои — единственные причины злиться.
     * Обычные неудачи остаются грустными, а не «злыми просто так».
     */
    protected function errorPersona(string $message): BumiPersona
    {
        $haystack = mb_strtolower($message);
        $denied = str_contains($haystack, 'доступ')
            || str_contains($haystack, 'права')
            || str_contains($haystack, 'разреш')
            || str_contains($haystack, '403');

        if ($denied) {
            return BumiPersona::make(
                BumiMood::Angry,
                'denied',
                __('Не пускают — значит, так надо. Пойдём обходным путём.'),
            );
        }

        return BumiPersona::make(
            BumiMood::Sad,
            'error',
            __('Ой. Кажется, что-то пошло не так. Я рядом — попробуем ещё.'),
        );
    }
}
