<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\NumberSequence;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * BL-07/DB-07/DB-08: общий генератор номеров вместо `COUNT()+1`.
 *
 * Счётчик хранится в number_sequences и инкрементируется под `SELECT … FOR UPDATE`
 * в транзакции вызывающего кода. Уникальный индекс (type, year) не даёт двум
 * параллельным транзакциям получить один и тот же номер; SQLite в тестах
 * игнорирует FOR UPDATE, но последовательные тесты всё равно проверяют
 * уникальность и корректный старт с учётом.legaси-данных.
 */
final class NumberGenerator
{
    /**
     * Следующее значение последовательности (1-based).
     *
     * @param  callable(): int  $seed  максимальный уже использованный номер (0, если записей нет)
     */
    public function next(string $type, int|string $year, callable $seed): int
    {
        return DB::transaction(function () use ($type, $year, $seed) {
            $year = (string) $year;

            $row = NumberSequence::query()
                ->where('type', $type)
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                try {
                    $row = NumberSequence::create([
                        'type' => $type,
                        'year' => $year,
                        'last_value' => max(0, (int) $seed()),
                    ]);
                } catch (QueryException) {
                    // Параллельная транзакция создала строку первее: перечитываем её
                    // уже под блокировкой и продолжаем с её счётчика.
                    $row = NumberSequence::query()
                        ->where('type', $type)
                        ->where('year', $year)
                        ->lockForUpdate()
                        ->firstOrFail();
                }
            }

            // Атомарный инкремент: в MySQL — UPDATE последней заблокированной строки.
            $row->increment('last_value');

            return (int) $row->last_value;
        });
    }

    /**
     * Максимальный числовой суффикс среди номеров вида `PREFIX-NNNNNN`.
     * Используется как начальное значение последовательности, чтобы новые
     * номера не пересеклись с уже существующими (включая soft-deleted).
     *
     * @param  iterable<mixed>  $numbers
     */
    public function maxSuffix(string $prefix, iterable $numbers): int
    {
        $max = 0;
        foreach ($numbers as $number) {
            $value = (string) $number;
            if (! str_starts_with($value, $prefix)) {
                continue;
            }
            $suffix = substr($value, strlen($prefix));
            if ($suffix !== '' && ctype_digit($suffix)) {
                $max = max($max, (int) $suffix);
            }
        }

        return $max;
    }
}
