<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domains\Audit\Services\AuditService;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\User;
use App\Shared\Enums\EmployeeStatus;
use App\Support\TenantGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Учётные записи (users.manage, ТЗ): просмотр, создание учёток, сброс пароля.
 * Управление ролями выполняется через employees.roles (EmployeeController).
 */
class UserController extends Controller
{
    public function __construct(
        protected AuditService $audit,
    ) {}

    public function index(Request $request): View
    {
        $me = $request->user()->employee;
        $companyScope = $me?->company_id;
        abort_if($companyScope === null, 403);

        $users = User::query()
            ->with('employee.department')
            ->whereHas('employee', fn ($e) => $e->where('company_id', $companyScope))
            ->orderBy('email')
            ->limit(300)
            ->get();

        // роли через таблицу employee_roles (joining, без MorphToMany)
        $roleMap = [];
        $rows = DB::table('employee_roles')
            ->join('roles', 'roles.id', '=', 'employee_roles.role_id')
            ->join('employees', 'employees.id', '=', 'employee_roles.employee_id')
            ->whereIn('employees.user_id', $users->pluck('id'))
            ->select('employees.user_id', 'roles.name')
            ->get();

        foreach ($rows as $row) {
            $roleMap[$row->user_id][] = $row->name;
        }

        // кто в системе вообще без карточки сотрудника
        $employeesWithUser = Employee::query()->where('company_id', $companyScope)->whereNotNull('user_id')->pluck('user_id');

        // кандидаты на привязку: активные сотрудники ещё без учётки
        $candidates = Employee::query()
            ->where('company_id', $companyScope)
            ->where('status', 'active')
            ->whereNull('user_id')
            ->orderBy('last_name')
            ->get();

        return view('admin.users', [
            'users' => $users,
            'roleMap' => $roleMap,
            'employeesWithUser' => $employeesWithUser,
            'candidates' => $candidates,
        ]);
    }

    /** Мой профиль: собственные данные, только чтение (auth, без employees.view). */
    public function profile(Request $request): View
    {
        $employee = $request->user()->employee()
            ->with(['company', 'department', 'position'])
            ->first();

        return view('profile', [
            'employee' => $employee,
            'roles' => $employee !== null
                ? $employee->roles()->orderBy('name')->pluck('name')
                : collect(),
        ]);
    }

    /**
     * Обновление собственных личных данных (адрес, телефон, дата рождения, e-mail, Telegram).
     * Пользователь редактирует ТОЛЬКО свою карточку — поля строго ограничены списком ниже.
     */
    public function updateProfile(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^\+[1-9][\d\s()\-]{6,24}$/'],
            'address' => ['nullable', 'string', 'max:255'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'email' => ['nullable', 'email', 'max:150'],
            'telegram_chat_id' => ['nullable', 'string', 'max:64'],
        ], [
            'phone.regex' => 'Формат: международный номер, начинается с +7/+992/… (например +7 (999) 123-45-67).',
        ]);

        $user = $request->user();
        $employee = $user->employee;

        if ($employee === null) {
            return back()->with('status', __('Нет карточки сотрудника'));
        }

        $old = $employee->only(array_keys($validated));
        $changed = [];

        foreach ($validated as $key => $value) {
            $before = $old[$key] ?? null;
            if ($before instanceof \DateTimeInterface) {
                $before = $before->format('Y-m-d');
            }
            if (trim((string) ($before ?? '')) !== trim((string) ($value ?? ''))) {
                $changed[] = $key;
            }
        }

        if ($changed !== []) {
            $employee->fill($validated)->save();

            $this->audit->log($user, 'profile.updated', 'employee', $employee->id, [
                'fields' => $changed,
                'old' => Arr::only($old, $changed),
            ], Arr::only($validated, $changed));
        }

        return back()->with('status', __('Профиль обновлён'));
    }

    /** Загрузка фото профиля: public-диск storage/app/public/avatars, старое фото удаляется. */
    public function uploadPhoto(Request $request): RedirectResponse
    {
        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:2048'],
        ]);

        $user = $request->user();
        $employee = $user->employee;

        if ($employee === null) {
            return back()->with('status', __('Нет карточки сотрудника'));
        }

        /** @var UploadedFile $file */
        $file = $request->file('photo');
        $extension = strtolower($file->guessExtension() ?: 'jpg');
        $name = $employee->id.'_'.Str::random(10).'.'.$extension;

        Storage::disk('public')->putFileAs('avatars', $file, $name);

        $oldPath = $employee->photo_path;
        $newPath = 'avatars/'.$name;
        $employee->update(['photo_path' => $newPath]);

        if (is_string($oldPath) && $oldPath !== '' && $oldPath !== $newPath) {
            Storage::disk('public')->delete($oldPath);
        }

        $this->audit->log($user, 'profile.photo_changed', 'employee', $employee->id, ['photo_path' => $oldPath], ['photo_path' => $newPath]);

        return back()->with('status', __('Фото профиля обновлено'));
    }

    /** Подготавливает только известные данные; сохранение требует отправки формы. */
    public function aiFillProfile(Request $request): RedirectResponse
    {
        $user = $request->user();
        $employee = $user->employee;
        if ($employee === null) {
            abort(403, __('Нет карточки сотрудника'));
        }
        $values = [];
        if (blank($employee->email) && filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
            $values['email'] = $user->email;
        }
        if ($values === []) {
            return back()->with('status', __('Нет подтверждённых данных для автозаполнения. Укажите сведения вручную.'));
        }
        $this->audit->log($user, 'profile.suggestions_prepared', 'employee', $employee->id,
            null, ['fields' => array_keys($values), 'source' => 'current_account']);

        return back()->with('profile_suggestions', $values)
            ->with('status', __('Подготовлены данные учётной записи. Проверьте форму и нажмите «Сохранить».'));
    }

    /** Смена собственного пароля: текущий проверяется, новый — с подтверждением. */
    public function changePassword(Request $request): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'current_password:web'],
            'password' => ['required', 'string', Password::min(12), 'confirmed'],
        ], [
            'password.min' => __('Новый пароль должен содержать не менее 12 символов.'),
        ]);

        $user = $request->user();
        $user->forceFill(['password' => $request->input('password')])->save();
        $user->revokeSessions();
        $request->session()->put('auth_version', (int) $user->auth_version);

        $this->audit->log($user, 'profile.password_changed', 'user', $user->id, null, null);

        return back()->with('status', __('Пароль изменён'));
    }

    /** Создание учётной записи (сотруднику или пустой). */
    public function store(Request $request): RedirectResponse
    {
        $me = $request->user()->employee;

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'string', 'email', 'max:150', 'unique:users,email'],
            'employee_id' => ['nullable', 'exists:employees,id'],
            'password' => ['nullable', 'string', Password::min(12)],
        ], [
            'password.min' => __('Пароль должен содержать не менее 12 символов.'),
        ]);

        // привязать учётку можно только к сотруднику своей компании
        $employeeId = $validated['employee_id'] ?? null;
        if ($employeeId !== null) {
            TenantGuard::ensureReference('employees', (int) $employeeId, $me, __('Сотрудник из другой компании'));
        }

        $password = $validated['password'] ?? null;
        $isManual = is_string($password) && $password !== '';
        if ($isManual === false) {
            $password = Str::random(16);
        }

        $user = DB::transaction(function () use ($validated, $employeeId, $password): User {
            $employee = $employeeId !== null
                ? Employee::query()->whereKey($employeeId)->lockForUpdate()->firstOrFail()
                : null;
            if ($employee !== null && ($employee->user_id !== null || $employee->status !== EmployeeStatus::Active)) {
                throw ValidationException::withMessages(['employee_id' => __('Сотрудник неактивен или уже имеет учётную запись')]);
            }
            $user = User::create(['name' => $validated['name'], 'email' => $validated['email'], 'password' => $password]);
            $employee?->update(['user_id' => $user->id]);

            return $user;
        });

        $this->audit->log($request->user(), 'user.created', 'user', $user->id, null, [
            'email' => $user->email,
            'employee_id' => $employeeId,
        ]);

        return back()->with('status', $isManual
            ? 'Учётка '.$user->email.' создана'
            : 'Учётка '.$user->email.' создана. Временный пароль (одноразово): '.$password);
    }

    /** Сброс пароля: выдаём или задаём новый. */
    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        // межтенантная изоляция: сброс пароля только для учёток своей компании
        TenantGuard::ensureUser($user, $request->user()->employee);

        $request->validate([
            'password' => ['nullable', 'string', Password::min(12)],
        ], [
            'password.min' => __('Новый пароль должен содержать не менее 12 символов.'),
        ]);

        $password = $request->input('password');
        $isManual = is_string($password) && $password !== '';
        if ($isManual === false) {
            $password = Str::random(16);
        }

        $user->forceFill(['password' => $password])->save(); // каст hashed
        $user->revokeSessions();

        $this->audit->log($request->user(), 'user.password_reset', 'user', $user->id, null, [
            'email' => $user->email,
            'manual' => $isManual,
        ]);

        return back()->with('status', $isManual
            ? 'Пароль учётки '.$user->email.' изменён'
            : 'Пароль учётки '.$user->email.' сброшен. Новый пароль (одноразово): '.$password);
    }
}
