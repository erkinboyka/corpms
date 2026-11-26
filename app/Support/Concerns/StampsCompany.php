<?php

declare(strict_types=1);

namespace App\Support\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Автоматическая печать company_id на новых записях.
 *
 * Явное значение из контроллера/очереди всегда имеет приоритет — трейт
 * подставляет компанию только когда поле пустое и есть авторизованный
 * сотрудник. В фоновом контексте (нет пользователя) запись остаётся как есть:
 * задачи обязаны передавать companyId явно.
 */
trait StampsCompany
{
    protected static function bootStampsCompany(): void
    {
        static::creating(static function (Model $model): void {
            if ($model->getAttribute('company_id') !== null) {
                return;
            }

            if (! method_exists($model, 'activeCompanyId')) {
                return;
            }

            $companyId = $model::activeCompanyId();

            if ($companyId !== null) {
                $model->setAttribute('company_id', $companyId);
            }
        });
    }
}
