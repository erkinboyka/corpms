<?php

declare(strict_types=1);

namespace App\Support\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Явные company-скоупы для моделей внутри тенанта.
 *
 * Не глобальный scope: фоновые задачи и служебные команды работают без
 * авторизованного пользователя, но каждая читающая точка должна сама
 * вызвать forCompany()/activeCompany(). Если компания не определена —
 * запрос возвращает пустой набор (fail-closed), а не весь мир.
 */
trait ScopesActiveCompany
{
    /** Текущая компания авторизованного сотрудника. */
    public static function activeCompanyId(): ?int
    {
        $companyId = Auth::user()?->employee?->company_id;

        return $companyId === null ? null : (int) $companyId;
    }

    /**
     * Скоуп по компании. Модели без company_id указывают связь до модели,
     * у которой он есть (например, BiWidget → dashboard).
     */
    public function scopeForCompany(Builder $query, ?int $companyId = null): Builder
    {
        $companyId ??= static::activeCompanyId();

        if ($companyId === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($this->hasOwnCompanyColumn()) {
            return $query->where($this->qualifyColumn('company_id'), $companyId);
        }

        return $query->whereHas($this->companyScopeRelation(), static function (Builder $related) use ($companyId): void {
            $related->where('company_id', $companyId);
        });
    }

    /** Алиас для вызовов вида BiDashboard::query()->activeCompany()->get(). */
    public function scopeActiveCompany(Builder $query): Builder
    {
        return $this->scopeForCompany($query);
    }

    /** Связь до модели с company_id (переопределяется в BiWidget). */
    protected function companyScopeRelation(): string
    {
        return 'company';
    }

    protected function hasOwnCompanyColumn(): bool
    {
        return $this->getConnection()
            ->getSchemaBuilder()
            ->hasColumn($this->getTable(), 'company_id');
    }
}
