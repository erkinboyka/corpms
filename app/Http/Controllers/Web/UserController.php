<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domains\Audit\Services\AuditService;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\User;
use App\Support\SettingsService;
use App\Support\TenantGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Throwable;

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

        $users = User::query()
            ->with('employee.department')
            // межтенантная изоляция: учётки сотрудников своей компании и непривязанные
            // (без карточки сотрудника нельзя определить компанию — они общие)
            ->where(function ($q) use ($companyScope) {
                $q->whereDoesntHave('employee')
                    ->orWhereHas('employee', fn ($e) => $e->where('company_id', $companyScope));
            })
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
        $employeesWithUser = Employee::query()->whereNotNull('user_id')->pluck('user_id');

        // кандидаты на привязку: активные сотрудники ещё без учётки
        $candidates = Employee::query()
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
            return back()->with('status', 'Нет карточки сотрудника');
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

        return back()->with('status', 'Профиль обновлён');
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
            return back()->with('status', 'Нет карточки сотрудника');
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

        return back()->with('status', 'Фото профиля обновлено');
    }

    /**
     * AI-автозаполнение ПУСТЫХ полей собственной карточки (адрес, дата рождения, e-mail, Telegram).
     * Заполненные поля и телефон/фото не трогаем. Без LLM или при ошибке — детерминированный локальный филлер.
     */
    public function aiFillProfile(Request $request): RedirectResponse
    {
        $user = $request->user();
        $employee = $user->employee;

        if ($employee === null) {
            return back()->with('status', 'Нет карточки сотрудника');
        }

        // заполняем только пустые поля из разрешённого списка
        $emptyFields = [];
        foreach (['address', 'birth_date', 'email', 'telegram_chat_id'] as $field) {
            $current = $employee->getAttribute($field);
            if ($current === null || trim((string) $current) === '') {
                $emptyFields[] = $field;
            }
        }

        if ($emptyFields === []) {
            return back()->with('status', 'Все поля уже заполнены — AI заполнять нечего');
        }

        $llmValues = $this->aiLlmFill($employee, $emptyFields);

        $values = [];
        foreach ($emptyFields as $field) {
            $value = $llmValues[$field] ?? null;
            if ($this->aiValueValid($field, $value) === false) {
                $value = $this->aiLocalFill($employee, $field);
            }
            if ($value !== null && trim((string) $value) !== '') {
                $values[$field] = $value;
            }
        }

        if ($values === []) {
            return back()->with('status', 'AI не смог подобрать данные — заполните поля вручную');
        }

        $employee->update($values);

        $this->audit->log($user, 'profile.ai_filled', 'employee', $employee->id, [
            'fields' => array_keys($values),
            'source' => $llmValues !== [] ? 'llm' : 'local',
        ], $values);

        return back()->with('status', 'AI заполнил '.count($values).' полей (проверьте данные)');
    }

    /* -------------------- AI-заполнение профиля -------------------- */

    /** Запрос к OpenAI-совместимому LLM (corpms.ai.*); при любой ошибке — []. */
    protected function aiLlmFill(Employee $employee, array $fields): array
    {
        $settings = app(SettingsService::class);
        $baseUrl = rtrim((string) $settings->get('corpms.ai.base_url'), '/');
        $apiKey = (string) $settings->get('corpms.ai.api_key');

        if ($baseUrl === '' || $apiKey === '') {
            return [];
        }

        $model = (string) ($settings->get('corpms.ai.model') ?? 'gpt-4o-mini');

        $context = [
            'ФИО' => trim($employee->last_name.' '.$employee->first_name.' '.($employee->middle_name ?? '')),
            'компания' => $employee->company?->name,
            'регион' => $employee->company?->region,
            'часовой пояс' => $employee->company?->timezone,
            'должность' => $employee->position?->name,
            'поля' => $fields,
        ];

        $system = 'Ты помогаешь заполнить кадровую карточку корпоративной системы. '
            .'Ответь ТОЛЬКО JSON-объектом без markdown и пояснений, вида: '
            .'{"address": "г. …, ул. …, 1", "birth_date": "YYYY-MM-DD", "email": "…@…", "telegram": "@username"}. '
            .'Включай только запрошенные поля. Данные должны быть правдоподобны для сотрудника из указанного региона, '
            .'дата рождения — не позднее вчерашнего дня (возраст 22–60 лет).';

        try {
            $response = Http::withToken($apiKey)
                ->timeout(20)
                ->post($baseUrl.'/chat/completions', [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => 'Данные сотрудника: '.json_encode($context, JSON_UNESCAPED_UNICODE)
                            .PHP_EOL.'Заполни поля: '.implode(', ', $fields)],
                    ],
                    'temperature' => 0.4,
                ]);
        } catch (Throwable) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $content = $response->json('choices.0.message.content');
        if (! is_string($content) || trim($content) === '') {
            return [];
        }

        $decoded = $this->aiDecodeJson(trim($content));
        if (! is_array($decoded)) {
            return [];
        }

        // нормализуем ключи: LLM может вернуть "telegram" вместо "telegram_chat_id"
        $map = ['address' => 'address', 'birth_date' => 'birth_date', 'email' => 'email', 'telegram' => 'telegram_chat_id', 'telegram_chat_id' => 'telegram_chat_id'];
        $out = [];
        foreach ($decoded as $key => $value) {
            $target = $map[mb_strtolower(trim((string) $key))] ?? null;
            if ($target !== null && is_scalar($value)) {
                $out[$target] = trim((string) $value);
            }
        }

        return $out;
    }

    /** Оборонительный разбор ответа LLM: снимает ```json-ограждения, ищет первый {...}. */
    protected function aiDecodeJson(string $content): ?array
    {
        $content = (string) preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $content);

        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($content, $start, $end - $start + 1), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /** Проверка значения, присланного LLM (или фолбэка) перед записью в БД. */
    protected function aiValueValid(string $field, mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        $value = trim($value);
        if ($value === '') {
            return false;
        }

        return match ($field) {
            'address' => mb_strlen($value) <= 255,
            'birth_date' => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)
                && strtotime($value) !== false
                && strtotime($value) < time(),
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false && mb_strlen($value) <= 150,
            'telegram_chat_id' => mb_strlen($value) <= 64
                && (bool) preg_match('/^@?[A-Za-z0-9_]{3,32}$|^\d{5,20}$/', $value),
            default => false,
        };
    }

    /** Локальный детерминированный филлер (без LLM): адрес по региону, e-mail, дата рождения, telegram. */
    protected function aiLocalFill(Employee $employee, string $field): ?string
    {
        switch ($field) {
            case 'address':
                $region = trim((string) $employee->company?->region);
                if ($region === '' || str_contains(mb_strtolower($region), 'не указан')) {
                    return 'г. Душанбе, пр. Исмоила Сомони, 1';
                }
                if (preg_match('/^г\.?\s/iu', $region) !== 1) {
                    $region = 'г. '.$region;
                }

                return $region.', ул. Ленина, 1';

            case 'birth_date':
                // псевдослучайно, но детерминированно: 1985–1998, стабильно по id
                $id = (int) $employee->id;
                $year = 1985 + ($id * 37 % 14);
                $month = 1 + ($id * 7 % 12);
                $day = 1 + ($id * 11 % 28);

                return sprintf('%04d-%02d-%02d', $year, $month, $day);

            case 'email':
                $local = $this->aiTranslit($employee->first_name).'.'.$this->aiTranslit($employee->last_name);
                $local = strtolower((string) preg_replace('/[^a-z0-9._-]+/', '', $local));
                if ($local === '' || $local === '.') {
                    $local = 'employee'.$employee->id;
                }
                $userEmail = (string) $employee->user?->email;
                $at = strrpos($userEmail, '@');
                $domain = $at !== false ? substr($userEmail, $at + 1) : 'example.com';
                if (! filter_var('a@'.$domain, FILTER_VALIDATE_EMAIL)) {
                    $domain = 'example.com';
                }

                return $local.'@'.$domain;

            case 'telegram_chat_id':
                $translit = $this->aiTranslit($employee->first_name);
                if ($translit === '') {
                    $translit = 'user'.$employee->id;
                }

                return '@'.$translit;

            default:
                return null;
        }
    }

    /** Простая кириллица → латиница (для e-mail/telegram). */
    protected function aiTranslit(string $value): string
    {
        $map = [
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e',
            'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm',
            'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
            'ф' => 'f', 'х' => 'h', 'ц' => 'c', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch', 'ъ' => '',
            'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
        ];

        $lower = mb_strtolower($value);
        $out = '';
        $length = mb_strlen($lower);

        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($lower, $i, 1);
            $out .= $map[$char] ?? $char;
        }

        return (string) preg_replace('/[^a-z0-9]+/', '', $out);
    }

    /** Смена собственного пароля: текущий проверяется, новый — с подтверждением. */
    public function changePassword(Request $request): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'current_password:web'],
            'password' => ['required', 'string', Password::min(12), 'confirmed'],
        ], [
            'password.min' => 'Новый пароль должен содержать не менее 12 символов.',
        ]);

        $user = $request->user();
        $user->forceFill(['password' => $request->input('password')])->save();

        $this->audit->log($user, 'profile.password_changed', 'user', $user->id, null, null);

        return back()->with('status', 'Пароль изменён');
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
            'password.min' => 'Пароль должен содержать не менее 12 символов.',
        ]);

        // привязать учётку можно только к сотруднику своей компании
        if ($validated['employee_id'] !== null) {
            TenantGuard::ensureReference('employees', (int) $validated['employee_id'], $me, 'Сотрудник из другой компании');
        }

        $password = $validated['password'] ?? null;
        $isManual = is_string($password) && $password !== '';
        if ($isManual === false) {
            $password = Str::random(16);
        }

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $password,
        ]);

        if ($validated['employee_id'] !== null) {
            Employee::query()
                ->whereKey((int) $validated['employee_id'])
                ->whereNull('user_id')
                ->update(['user_id' => $user->id]);
        }

        $this->audit->log($request->user(), 'user.created', 'user', $user->id, null, [
            'email' => $user->email,
            'employee_id' => $validated['employee_id'],
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
            'password.min' => 'Новый пароль должен содержать не менее 12 символов.',
        ]);

        $password = $request->input('password');
        $isManual = is_string($password) && $password !== '';
        if ($isManual === false) {
            $password = Str::random(16);
        }

        $user->forceFill(['password' => $password])->save(); // каст hashed

        $this->audit->log($request->user(), 'user.password_reset', 'user', $user->id, null, [
            'email' => $user->email,
            'manual' => $isManual,
        ]);

        return back()->with('status', $isManual
            ? 'Пароль учётки '.$user->email.' изменён'
            : 'Пароль учётки '.$user->email.' сброшен. Новый пароль (одноразово): '.$password);
    }
}
