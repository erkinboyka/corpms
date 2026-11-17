<?php

declare(strict_types=1);

namespace App\Domains\Ai\Services;

use App\Models\AiMemory;
use App\Models\Employee;
use Illuminate\Support\Collection;
use Throwable;

/**
 * D6 (AI Memory): долговременная память ассистента о сотруднике.
 *
 * Записи создаются только по явной команде пользователя («запомни…»),
 * автоматически ничего не сохраняется. Лимиты: ключ ≤64, значение ≤500,
 * не больше 50 записей на сотрудника (старые вытесняются).
 */
class AiMemoryService
{
    /** Максимум записей памяти на сотрудника. */
    public const MAX_MEMORIES = 50;

    /** Максимальная длина ключа/значения. */
    public const MAX_KEY = 64;

    public const MAX_VALUE = 500;

    /** Сколько записей уходит в системный промпт LLM. */
    public const CONTEXT_LIMIT = 20;

    /** Запись или обновление пары key/value (upsert по employee_id + key). */
    public function remember(Employee $employee, string $key, string $value, string $source = 'user'): ?AiMemory
    {
        $key = $this->normalizeKey($key);
        $value = mb_substr(trim($value), 0, self::MAX_VALUE);

        if ($key === '' || $value === '') {
            return null;
        }

        try {
            $memory = AiMemory::query()->updateOrCreate(
                ['employee_id' => $employee->id, 'memory_key' => $key],
                ['memory_value' => $value, 'source' => $source],
            );

            $this->trimToLimit($employee);

            return $memory;
        } catch (Throwable) {
            return null; // память не должна ломать ответ
        }
    }

    /** Все записи памяти сотрудника (свежие раньше). */
    public function recall(Employee $employee): Collection
    {
        try {
            return AiMemory::query()
                ->where('employee_id', $employee->id)
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->limit(self::MAX_MEMORIES)
                ->get();
        } catch (Throwable) {
            return collect();
        }
    }

    /** Удаление одной записи по ключу. Возвращает true, если что-то удалили. */
    public function forget(Employee $employee, string $key): bool
    {
        $key = $this->normalizeKey($key);
        if ($key === '') {
            return false;
        }

        try {
            return AiMemory::query()
                ->where('employee_id', $employee->id)
                ->where('memory_key', $key)
                ->delete() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    /** Блок «Память сотрудника» для системного промпта LLM ('' — память пуста). */
    public function contextBlock(Employee $employee): string
    {
        $memories = $this->recall($employee);
        if ($memories->isEmpty()) {
            return '';
        }

        $lines = [];
        foreach ($memories->take(self::CONTEXT_LIMIT) as $memory) {
            $lines[] = $memory->memory_key.': '.$memory->memory_value;
        }

        return "\nПамять о сотруднике (используй, если относится к вопросу):\n".implode("\n", $lines);
    }

    /** Нормализация ключа: trim + lowercase + потолок длины. */
    public function normalizeKey(string $key): string
    {
        $key = mb_strtolower(trim($key));
        $key = trim($key, " \t.,!?:;\"'«»");

        return mb_substr($key, 0, self::MAX_KEY);
    }

    /** Вытеснение старейших записей при превышении лимита. */
    private function trimToLimit(Employee $employee): void
    {
        $overflow = AiMemory::query()
            ->where('employee_id', $employee->id)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->skip(self::MAX_MEMORIES)
            ->limit(self::MAX_MEMORIES)
            ->pluck('id');

        if ($overflow->isNotEmpty()) {
            AiMemory::query()->whereIn('id', $overflow)->delete();
        }
    }
}
