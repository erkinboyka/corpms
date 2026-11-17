<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\AppNotification;
use App\Models\AttendanceAnomaly;
use App\Models\AttendanceCorrection;
use App\Models\BiometricProfile;
use App\Models\Company;
use App\Models\DocumentSignature;
use App\Models\DocumentSignatureRequest;
use App\Models\DocumentVersion;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\RfidCard;
use App\Models\TaskAttachment;
use App\Models\User;
use App\Support\PermissionService;
use App\Support\TenantGuard;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Обязательная точка межтенантной изоляции (мультиарендность).
 *
 * Работает после SubstituteBindings: проходит по всем route-параметрам и
 * сверяет company_id каждого Eloquent-объекта с компанией сотрудника текущего
 * пользователя (TenantGuard). Любая модель из другого тенанта — 403, включая
 * прямой доступ super_admin по ID (глобальный обзор — только через скоуп
 * в списках). Единственное исключение — сам справочник компаний (см.
 * ensureTenant): управление тенантами выполняет только платформенная роль.
 *
 * Модели без собственного company_id проверяются по цепочке связей до
 * модели с company_id (см. RELATED). Неизвестная модель без company_id —
 * отказ по умолчанию (fail-closed), а не пропуск.
 *
 * Дополнительно сверяются вложенные параметры маршрута: если у модели есть
 * FK на родительский параметр ({document} → document_id), он обязан
 * совпадать с объектом из маршрута — иначе 404 (подмена родителя без
 * утечки существования чужой записи).
 *
 * Глобальные праздники (holidays.company_id = NULL) доступны всем тенантам.
 */
final class EnsureTenantIsolation
{
    /**
     * Модели без company_id: последовательность связей до модели с company_id.
     *
     * @var array<class-string<Model>, array<int, string>>
     */
    private const RELATED = [
        LeaveRequest::class => ['employee'],
        User::class => ['employee'],
        DocumentSignature::class => ['document'],
        DocumentSignatureRequest::class => ['document'],
        DocumentVersion::class => ['document'],
        AttendanceAnomaly::class => ['employee'],
        AttendanceCorrection::class => ['employee'],
        RfidCard::class => ['employee'],
        BiometricProfile::class => ['employee'],
        AppNotification::class => ['recipient'],
        TaskAttachment::class => ['task'],
    ];

    public function __construct(private PermissionService $permissions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();

        if ($route === null) {
            return $next($request);
        }

        $parameters = $route->parameters();
        $models = array_filter($parameters, static fn (mixed $value): bool => $value instanceof Model);

        if ($models === []) {
            return $next($request);
        }

        $user = $request->user();

        // Гость: пропускаем — маршрутов с моделями вне auth-группы нет,
        // redirect на login сделает middleware 'auth'.
        if ($user === null) {
            return $next($request);
        }

        $me = $this->permissions->employee($user);

        // Учётка без сотрудника не имеет компании — доступ закрыт (fail-closed).
        if ($me === null) {
            abort(403, 'Объект недоступен: учётная запись не привязана к сотруднику');
        }

        $this->assertNestedParametersMatch($models);

        // Справочник компаний — исключение из тенант-проверки (см. ensureTenant).
        $isPlatformAdmin = $this->permissions->roleNames($user)->contains('super_admin');

        foreach ($models as $model) {
            $this->ensureTenant($model, $me, $isPlatformAdmin);
        }

        return $next($request);
    }

    /**
     * Проверка одного объекта на принадлежность компании текущего пользователя.
     *
     * Исключение: записи таблицы `companies` — это сами тенанты, а не данные
     * внутри тенанта. Управление справочником компаний (п.5 ТЗ) выполняет
     * только платформенная роль super_admin (единственная с companies.manage):
     * иначе ветка «удалить пустую компанию» становится недостижимой —
     * удалять свой тенант нельзя (в нём есть сам админ), а чужой — запрещён.
     * Данные внутри компании (сотрудники, документы, задачи, финансы…)
     * изолированы для всех ролей, включая super_admin.
     */
    private function ensureTenant(Model $model, Employee $me, bool $isPlatformAdmin): void
    {
        // Компания сама по себе: ID маршрута и есть company_id.
        if ($model instanceof Company) {
            if ($isPlatformAdmin === false) {
                TenantGuard::ensureCompany((int) $model->id, $me);
            }

            return;
        }

        $attributes = $model->getAttributes();

        if (array_key_exists('company_id', $attributes)) {
            // Глобальный праздник (company_id NULL) видят все тенанты.
            if ($model instanceof Holiday && $model->getAttribute('company_id') === null) {
                return;
            }

            $companyId = $model->getAttribute('company_id');
            TenantGuard::ensureCompany($companyId === null ? null : (int) $companyId, $me);

            return;
        }

        $relations = self::RELATED[$model::class] ?? null;

        if ($relations === null) {
            // Новая модель попала в маршрут без company_id и без описания
            // связей — отказ по умолчанию, а не молчаливый пропуск.
            abort(403, 'Доступ к объекту запрещён: не настроена проверка компании');
        }

        TenantGuard::ensureRelated($model, $me, $relations);
    }

    /**
     * Вложенные объекты маршрута обязаны быть связаны между собой:
     * signatureRequest.document_id === document.id, attachment.task_id === task.id…
     *
     * @param  array<string, mixed>  $parameters
     */
    private function assertNestedParametersMatch(array $parameters): void
    {
        foreach ($parameters as $childName => $child) {
            if ($child instanceof Model === false) {
                continue;
            }

            foreach ($parameters as $parentName => $parent) {
                if ($childName === $parentName || $parent instanceof Model === false) {
                    continue;
                }

                $foreignKey = $parentName.'_id';

                if (array_key_exists($foreignKey, $child->getAttributes()) === false) {
                    continue;
                }

                $value = $child->getAttribute($foreignKey);

                if ($value !== null && (int) $value !== (int) $parent->id) {
                    // 404, а не 403: несоответствие родителя и дочернего объекта
                    // — маршрут не разрешается (без утечки существования чужой записи).
                    abort(404, 'Объект не найден в контексте маршрута');
                }
            }
        }
    }
}
