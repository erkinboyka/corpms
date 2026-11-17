<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Biometric\Services\BiometricService;
use App\Http\Controllers\Controller;
use App\Models\BiometricDevice;
use App\Models\BiometricEnrollment;
use App\Models\BiometricEvent;
use App\Models\BiometricProfile;
use App\Models\Employee;
use App\Models\User;
use App\Shared\Enums\DeviceStatus;
use App\Shared\Enums\PermissionScope;
use App\Support\PermissionService;
use App\Support\SettingsService;
use App\Support\TenantGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Биометрия (п.24–27, 67 ТЗ): устройства, регистрация, профили сотрудников (enroll).
 */
class BiometricController extends Controller
{
    public function __construct(
        protected BiometricService $biometrics,
        protected SettingsService $settings,
        protected AuditService $audit,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $me = app(PermissionService::class)->employee($user);
        if ($me === null) {
            abort(403, 'Нет карточки сотрудника');
        }
        $companyId = $me->company_id;

        $offlineAfter = (int) $this->settings->get('corpms.biometric.offline_after_minutes', 15);
        $threshold = now()->subMinutes($offlineAfter);

        // межтенантная изоляция (S2): устройства, профили и энроллменты своей компании
        $devices = BiometricDevice::query()
            ->with(['company'])
            ->where('company_id', $companyId)
            ->withCount([
                'events as events_today' => fn ($q) => $q->whereDate('occurred_at', today()),
                'enrollments as enrollments_count',
            ])
            ->orderBy('name')
            ->limit(200)
            ->get();

        // статус "расчётный": online => last_seen свежее порога
        $devices->each(function (BiometricDevice $device) use ($threshold): void {
            $device->computed_status = ($device->status === DeviceStatus::Online && $device->last_seen_at !== null && $device->last_seen_at->greaterThanOrEqualTo($threshold))
                ? DeviceStatus::Online
                : DeviceStatus::Offline;
        });

        $profiles = BiometricProfile::query()
            ->whereHas('employee', fn ($q) => $q->where('company_id', $companyId))
            ->count();

        $enrolls = BiometricEnrollment::query()
            ->with(['employee', 'device'])
            ->whereHas('device', fn ($q) => $q->where('company_id', $companyId))
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        // профили сотрудника (для enroll-панели) — только своей компании
        $employees = Employee::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->orderBy('last_name')
            ->get();

        return view('biometrics.index', [
            'devices' => $devices,
            'profilesCount' => $profiles,
            'enrollments' => $enrolls,
            'employees' => $employees,
            'profiles' => BiometricProfile::query()
                ->with('employee')
                ->whereHas('employee', fn ($q) => $q->where('company_id', $companyId))
                ->orderByDesc('id')
                ->limit(50)
                ->get(),
            'offlineAfter' => $offlineAfter,
            'canManage' => auth()->user() instanceof User && app(PermissionService::class)->check(auth()->user(), 'biometry.devices.manage'),
            'canEnroll' => auth()->user() instanceof User && app(PermissionService::class)->check(auth()->user(), 'biometry.enroll'),
        ]);
    }

    public function registerDevice(Request $request): RedirectResponse
    {
        $user = auth()->user();
        \assert($user instanceof User);
        $me = app(PermissionService::class)->employee($user);
        if ($me === null) {
            abort(403, 'Нет карточки сотрудника');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'serial_number' => ['required', 'string', 'max:64', 'unique:biometric_devices,serial_number'],
            'device_type' => ['required', 'in:face_terminal,kiosk,fingerprint_gate,turnstile,qr_reader'],
            'vendor' => ['required', 'string', 'max:64'],
            'model' => ['nullable', 'string', 'max:120'],
            'location' => ['nullable', 'string', 'max:200'],
            'ip' => ['nullable', 'ip'],
            'firmware' => ['nullable', 'string', 'max:60'],
            'direction' => ['required', 'in:in,out,both'],
        ]);

        [$device, $plainToken] = $this->biometrics->registerDevice($validated + [
            'company_id' => $me->company_id,
        ]);

        $this->audit->log($user, 'device.registered', 'biometric_device', $device->id, null, [
            'serial_number' => $validated['serial_number'],
            'name' => $validated['name'],
        ]);

        // токен показываем только один раз (п.24 ТЗ)
        return redirect()->route('biometrics.index')->with([
            'status' => "Устройство {$validated['name']} зарегистрировано. API-токен (только один раз): ".$plainToken,
        ]);
    }

    public function updateDevice(Request $request, BiometricDevice $device): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:online,offline,disabled'],
            'location' => ['nullable', 'string', 'max:200'],
            'firmware' => ['nullable', 'string', 'max:60'],
        ]);

        $device->forceFill([
            'status' => $validated['status'],
            'location' => $validated['location'] ?? $device->location,
            'firmware' => $validated['firmware'] ?? $device->firmware,
        ])->save();

        $this->audit->log($request->user(), 'device.updated', 'biometric_device', $device->id, null, $validated);

        return back()->with('status', 'Устройство обновлено');
    }

    public function deleteDevice(BiometricDevice $device): RedirectResponse
    {
        $this->audit->log(auth()->user(), 'device.deleted', 'biometric_device', $device->id, ['serial_number' => $device->serial_number], null);
        $device->delete();

        return back()->with('status', 'Устройство удалено (события сохранены)');
    }

    /* -------------------- профили (enroll) -------------------- */

    public function enroll(Request $request): RedirectResponse
    {
        $user = auth()->user();
        \assert($user instanceof User);
        $me = app(PermissionService::class)->employee($user);
        if ($me === null) {
            abort(403, 'Нет карточки сотрудника');
        }

        $validated = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'device_id' => ['required', 'exists:biometric_devices,id'],
            'vendor' => ['required', 'string', 'max:64'],
            'template_ref' => ['nullable', 'string', 'max:255'],
            'template_hash' => ['required', 'string', 'max:128'],
        ]);

        $employee = Employee::query()->findOrFail($validated['employee_id']);
        $device = BiometricDevice::query()->findOrFail($validated['device_id']);

        // межтенантная изоляция (P0): ID приходят из тела запроса, middleware их не видит
        TenantGuard::ensureEmployee($employee, $me, 'Сотрудник принадлежит другой компании');
        TenantGuard::ensureCompany((int) $device->company_id, $me, 'Устройство принадлежит другой компании');

        // фиксируем enrollment-акт (журнал устройства)
        BiometricEnrollment::create([
            'device_id' => $device->id,
            'employee_id' => $employee->id,
            'operated_by' => $me->last_name.' '.$me->first_name,
        ]);

        $profile = $this->biometrics->enrollProfile(
            $employee,
            $validated['vendor'],
            $validated['template_ref'] ?? null,
            $validated['template_hash'],
        );
        $profile->forceFill(['enrolled_by' => $me->id])->save();

        $this->audit->log($user, 'biometry.enrolled', 'biometric_profile', $profile->id, null, [
            'employee_id' => $employee->id,
            'vendor' => $validated['vendor'],
        ]);

        return back()->with('status', 'Профиль биометрии создан: '.$employee->last_name);
    }

    public function disableProfile(BiometricProfile $profile): RedirectResponse
    {
        $user = auth()->user();
        \assert($user instanceof User);

        $this->biometrics->disableProfile($profile->employee, $profile->vendor);

        $this->audit->log($user, 'biometry.disabled', 'biometric_profile', $profile->id, null, [
            'employee_id' => $profile->employee_id,
            'vendor' => $profile->vendor,
        ]);

        return back()->with('status', 'Профиль деактивирован');
    }

    public function deleteProfile(BiometricProfile $profile): RedirectResponse
    {
        $user = auth()->user();
        \assert($user instanceof User);

        $this->biometrics->deleteProfile($profile->employee, $profile->vendor);

        $this->audit->log($user, 'biometry.deleted', 'biometric_profile', $profile->id, null, [
            'employee_id' => $profile->employee_id,
            'vendor' => $profile->vendor,
        ]);

        return back()->with('status', 'Профиль удалён');
    }

    /* -------------------- журнал биособытий (п.72 ТЗ) -------------------- */

    /** События устройств с ограничением области доступа по scope biometry.events.view. */
    public function events(Request $request): View
    {
        $user = $request->user();
        \assert($user instanceof User);
        $me = app(PermissionService::class)->employee($user);
        if ($me === null) {
            abort(403, 'Нет карточки сотрудника');
        }

        $permissions = app(PermissionService::class);
        $events = BiometricEvent::query()
            ->with(['device', 'employee'])
            ->orderByDesc('occurred_at')
            ->limit(300);

        // события не имеют company_id — отбираем через сотрудника/устройства своей компании
        $ownCompanyDeviceIds = BiometricDevice::query()->where('company_id', $me->company_id)->pluck('id');
        $events->where(fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('company_id', $me->company_id))
            ->orWhereIn('device_id', $ownCompanyDeviceIds));

        // scope biometry.events.view: own => только свои события
        $scope = $permissions->can($user, 'biometry.events.view');
        if ($scope === PermissionScope::Own) {
            $events->where('employee_id', $me->id);
        }

        if ($request->filled('device_id')) {
            $events->where('device_id', (int) $request->input('device_id'));
        }

        return view('biometrics.events', [
            'events' => $events->get(),
            'devices' => BiometricDevice::query()
                ->where('company_id', $me->company_id)
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    /* -------------------- политика приватности (п.73 ТЗ) -------------------- */

    /** Политика удержания биометрических данных (SettingsService). */
    public function privacy(Request $request): View
    {
        $me = app(PermissionService::class)->employee($request->user());
        if ($me === null) {
            abort(403, 'Нет карточки сотрудника');
        }

        return view('biometrics.privacy', [
            'requireLiveness' => (bool) $this->settings->get('corpms.biometric.require_liveness', false),
            'imageRetentionDays' => (int) $this->settings->get('corpms.biometric.image_retention_days', 30),
            'eventRetentionDays' => (int) $this->settings->get('corpms.biometric.event_retention_days', 365),
            // метрики — по событиям устройств своей компании (S2)
            'imagesWithRef' => BiometricEvent::query()
                ->whereNotNull('image_ref')
                ->whereHas('device', fn ($q) => $q->where('company_id', $me->company_id))
                ->count(),
            'oldestEventAt' => BiometricEvent::query()
                ->whereHas('device', fn ($q) => $q->where('company_id', $me->company_id))
                ->min('occurred_at'),
        ]);
    }

    /** Сохранение политики + опциональная немедленная очистка просроченного (п.73). */
    public function privacyUpdate(Request $request): RedirectResponse
    {
        $me = app(PermissionService::class)->employee($request->user());
        if ($me === null) {
            abort(403, 'Нет карточки сотрудника');
        }

        $validated = $request->validate([
            'require_liveness' => ['nullable', 'boolean'],
            'image_retention_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'event_retention_days' => ['required', 'integer', 'min:30', 'max:3650'],
            'purge_now' => ['nullable', 'boolean'],
        ]);

        $this->settings->set('corpms.biometric.require_liveness', (bool) ($validated['require_liveness'] ?? false));
        $this->settings->set('corpms.biometric.image_retention_days', (int) $validated['image_retention_days']);
        $this->settings->set('corpms.biometric.event_retention_days', (int) $validated['event_retention_days']);

        $purged = 0;
        if ((bool) ($validated['purge_now'] ?? false)) {
            $purged = $this->applyRetention(
                (int) $validated['image_retention_days'],
                (int) $validated['event_retention_days'],
                $me->company_id,
            );
        }

        $this->audit->log($request->user(), 'biometry.privacy.updated', 'setting', null, null, [
            'image_retention_days' => (int) $validated['image_retention_days'],
            'event_retention_days' => (int) $validated['event_retention_days'],
            'purged' => $purged,
        ]);

        return redirect()->route('biometrics.privacy')->with('status', 'Политика сохранена'.($purged > 0 ? ', удалено записей: '.$purged : ''));
    }

    /**
     * Применение retention-политики (п.73): удаляет сохранённые кадры (image_ref)
     * просроченных событий и события, отслужившие общий срок.
     *
     * @return int число удалённых записей
     */
    protected function applyRetention(int $imageRetentionDays, int $eventRetentionDays, ?int $companyId): int
    {
        // очистка только по устройствам своей компании (анти-кросс-тенантная запись)
        $ownCompany = fn ($query) => $query
            ->whereHas('device', fn ($q) => $q->where('company_id', $companyId));

        $events = BiometricEvent::query()
            ->whereNotNull('image_ref')
            ->where('occurred_at', '<', now()->subDays($imageRetentionDays))
            ->when($companyId !== null, $ownCompany)
            ->get();

        $removed = 0;
        foreach ($events as $event) {
            // кадры хранились в storage/app/private/… (image_ref = путь на диске local)
            Storage::disk('local')->delete($event->image_ref);

            $event->forceFill(['image_ref' => null])->save();
            $removed++;
        }

        $removed += BiometricEvent::query()
            ->where('occurred_at', '<', now()->subDays($eventRetentionDays))
            ->when($companyId !== null, $ownCompany)
            ->delete();

        return $removed;
    }
}
