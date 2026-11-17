<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Document;
use App\Models\DocumentSignature;
use App\Models\DocumentSignatureRequest;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Межтенантная изоляция (мультиарендность): сверяет company_id целевого объекта
 * с company_id сотрудника текущего пользователя и блокирует доступ (403).
 *
 * RBAC (PermissionService) отвечает на вопрос «какое право есть у роли»,
 * TenantGuard — на вопрос «принадлежит ли объект компании текущего пользователя».
 * Проверки вызываются в контроллерах после route-model-binding и до операций
 * с чужими ресурсами (чтение, изменение, согласование, подписи, сброс пароля).
 *
 * super_admin не получает послабления: глобальный обзор — через company-скоуп
 * в списках, а прямой доступ к чужому объекту по ID запрещён всем ролям.
 * Единственное исключение — записи самой таблицы companies (справочник
 * тенант-сущностей): их защищает отдельная точка EnsureTenantIsolation.
 */
final class TenantGuard
{
    /**
     * Проверка, что целевой company_id совпадает с компанией сотрудника пользователя.
     * При расхождении — 403.
     *
     * @param  int|string|null  $companyId  company_id целевого объекта
     */
    public static function ensureCompany(?int $companyId, ?Employee $me, string $message = 'Объект принадлежит другой компании'): void
    {
        if ($me === null || $me->company_id === null || $companyId === null) {
            abort(403, $message);
        }

        if ((int) $companyId !== (int) $me->company_id) {
            abort(403, $message);
        }
    }

    /**
     * Проверка модели с company_id (PayrollPeriod, Document, WorkSchedule, Holiday…).
     */
    public static function ensure(Model $model, ?Employee $me, string $message = 'Объект принадлежит другой компании'): void
    {
        $companyId = $model->getAttribute('company_id');

        // Модель без собственного company_id — проверяем через связь (см. ensureRelated).
        if ($companyId === null && $model->relationLoaded('company') === false && method_exists($model, 'company')) {
            $model->load('company');
            $companyId = $model->getAttribute('company_id');
        }

        self::ensureCompany($companyId === null ? null : (int) $companyId, $me, $message);
    }

    /**
     * Проверка моделей без company_id: скоупим через связанную модель.
     *
     * - LeaveRequest → employee→company_id
     * - User → employee→company_id
     * - DocumentSignatureRequest → document→company_id
     * - DocumentSignature → document→company_id
     *
     * @param  array<int, string>  $relations  последовательность связей до модели с company_id
     */
    public static function ensureRelated(Model $model, ?Employee $me, array $relations, string $message = 'Объект принадлежит другой компании'): void
    {
        $current = $model;

        foreach ($relations as $relation) {
            if (method_exists($current, $relation) === false) {
                abort(500, 'TenantGuard: связь '.$relation.' не определена у '.$current::class);
            }

            $current = $current->loadMissing($relation)->{$relation};

            if ($current instanceof Model === false) {
                // Связь пуста (null) — безопаснее запретить, чем пропустить.
                abort(403, $message);
            }
        }

        self::ensure($current, $me, $message);
    }

    /**
     * Сотрудник из компании текущего пользователя? (для полей ввода employee_id)
     */
    public static function ensureEmployee(Employee $employee, ?Employee $me, string $message = 'Сотрудник из другой компании'): void
    {
        self::ensureCompany((int) $employee->company_id, $me, $message);
    }

    /**
     * Пользователь (учётка) из компании текущего пользователя?
     * Учётка без сотрудника и сотрудник без компании — доступ запрещён.
     */
    public static function ensureUser(User $user, ?Employee $me, string $message = 'Учётная запись из другой компании'): void
    {
        $employee = $user->loadMissing('employee')->employee;

        if ($employee === null) {
            abort(403, $message);
        }

        self::ensureEmployee($employee, $me, $message);
    }

    /**
     * Ссылка из формы (department_id, position_id, work_schedule_id…) обязана
     * указывать на запись компании текущего пользователя (анти-перенос в чужую
     * организацию через подстановку ID).
     *
     * @param  string  $table  имя таблицы справочника с колонкой company_id
     */
    public static function ensureReference(string $table, int $id, ?Employee $me, string $message = 'Запись справочника принадлежит другой компании'): void
    {
        if ($me === null || $me->company_id === null) {
            abort(403, $message);
        }

        $belongs = DB::table($table)
            ->where('id', $id)
            ->where('company_id', $me->company_id)
            ->exists();

        if ($belongs === false) {
            abort(403, $message);
        }
    }

    /**
     * Документ по подписи — из компании текущего пользователя?
     */
    public static function ensureSignature(DocumentSignature $signature, ?Employee $me, string $message = 'Подпись принадлежит документу другой компании'): void
    {
        self::ensureRelated($signature, $me, ['document'], $message);
    }

    /**
     * Документ по запросу на подпись — из компании текущего пользователя?
     */
    public static function ensureSignatureRequest(DocumentSignatureRequest $request, Document|int|string|null $document, ?Employee $me, string $message = 'Запрос на подпись принадлежит другой компании'): void
    {
        // Документ из маршрута и документ запроса должны совпадать между собой,
        // иначе можно подписать чужой документ через подпись своего.
        $documentModel = $document instanceof Document ? $document : Document::query()->findOrFail((int) $document);

        if ((int) $request->document_id !== (int) $documentModel->id) {
            abort(403, $message);
        }

        self::ensure($documentModel, $me, $message);
    }

    /**
     * Документ-связка: принадлежит ли он компании текущего пользователя.
     */
    public static function ensureDocument(Document $document, ?Employee $me, string $message = 'Документ принадлежит другой компании'): void
    {
        self::ensure($document, $me, $message);
    }

    /**
     * Отпуск: заявка сотрудника из компании текущего пользователя.
     */
    public static function ensureLeave(LeaveRequest $leave, ?Employee $me, string $message = 'Заявка принадлежит другой компании'): void
    {
        self::ensureRelated($leave, $me, ['employee'], $message);
    }
}
