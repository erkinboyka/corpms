<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Маскировка уже записанных секретов в audit_logs (задача S4 аудита):
 * значения полей password/secret/token/api_key и подобных заменяются на «••••••».
 *
 * Изменение необратимо по содержимому (самих секретов в журнале больше нет),
 * но безопасно: затрагиваются только значения журнала, не данные приложения.
 */
return new class extends Migration
{
    private const SENSITIVE_KEY = '/password|passwd|secret|token|api[_-]?key|private[_-]?key|credential|otp/iu';

    public function up(): void
    {
        DB::table('audit_logs')
            ->where(function ($query): void {
                $query->whereNotNull('old_value')->orWhereNotNull('new_value');
            })
            ->orderBy('id')
            ->chunk(200, function ($logs): void {
                foreach ($logs as $log) {
                    $changed = false;

                    foreach (['old_value', 'new_value'] as $column) {
                        if ($log->{$column} === null) {
                            continue;
                        }

                        $decoded = json_decode((string) $log->{$column}, true);

                        if (! is_array($decoded)) {
                            continue;
                        }

                        $masked = $this->mask($decoded, $changed);

                        if ($masked !== $decoded) {
                            $log->{$column} = json_encode($masked, JSON_UNESCAPED_UNICODE);
                            $changed = true;
                        }
                    }

                    if ($changed) {
                        DB::table('audit_logs')->where('id', $log->id)->update([
                            'old_value' => $log->old_value,
                            'new_value' => $log->new_value,
                        ]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Очистка секретов необратима: исходные значения журнала не восстанавливаются.
    }

    /**
     * @param  array<mixed>  $data
     * @param  bool  $changed  изменён ли массив (ссылка наружу)
     * @return array<mixed>
     */
    private function mask(array $data, bool &$changed): array
    {
        $result = [];

        foreach ($data as $key => $item) {
            if (is_string($key) && preg_match(self::SENSITIVE_KEY, $key) === 1) {
                if ($item !== null && $item !== '' && $item !== '••••••') {
                    $changed = true;
                }

                $result[$key] = $item === null || $item === '' ? $item : '••••••';

                continue;
            }

            if (is_array($item)) {
                $nested = $this->mask($item, $changed);

                if ($nested !== $item) {
                    $changed = true;
                }

                $result[$key] = $nested;

                continue;
            }

            $result[$key] = $item;
        }

        return $result;
    }
};
