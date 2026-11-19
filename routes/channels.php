<?php

use App\Domains\Chat\Services\ChatService;
use App\Models\Conversation;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (string) $user->id === (string) $id;
});

/*
 * BM Chat: диалог. Доступ — только сотрудникам, которым ChatService
 * разрешает видеть диалог (членство или канал компании), той же компании.
 * Ошибки доступа не раскрывают существование чужих диалогов: всегда false.
 */
Broadcast::channel('chat.conversation.{conversationId}', function ($user, $conversationId) {
    $employee = $user->employee;

    if ($employee === null) {
        return false;
    }

    $conversation = Conversation::query()->find((int) $conversationId);

    if ($conversation === null || $conversation->company_id !== $employee->company_id) {
        return false;
    }

    try {
        app(ChatService::class)->assertCanView($employee, $conversation);

        return true;
    } catch (\Throwable) {
        return false;
    }
});

/*
 * Персональный канал уведомлений чата: бейджи непрочитанных, приглашения.
 * Видит только сам сотрудник — сверка id без дополнительных условий.
 */
Broadcast::channel('employee.{employeeId}.chat', function ($user, $employeeId) {
    return $user->employee !== null && (string) $user->employee->id === (string) $employeeId;
});
