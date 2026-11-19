<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class CreateAdministrator extends Command
{
    protected $signature = 'corpms:admin {email} {--company-code= : Код компании} {--company-name= : Название новой компании}';

    protected $description = 'Создать нового администратора с паролем через защищённый ввод';

    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        $code = trim((string) $this->option('company-code'));
        $name = trim((string) $this->option('company-name'));
        if (Validator::make(['email' => $email, 'code' => $code, 'name' => $name], [
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'code' => ['required', 'alpha_dash', 'max:32'], 'name' => ['nullable', 'string', 'max:255'],
        ])->fails()) {
            $this->error('Укажите новый email и корректный код компании. Существующие учётки не изменяются.');

            return self::INVALID;
        }
        $company = Company::where('code', $code)->first();
        if ($company === null && $name === '') {
            $this->error('Для новой компании требуется --company-name');

            return self::INVALID;
        }
        if (! $this->input->isInteractive()) {
            $this->error('Нужен интерактивный терминал для защищённого ввода пароля');

            return self::INVALID;
        }
        $password = $this->secret('Пароль администратора (минимум 12 символов)');
        $confirmation = $this->secret('Повторите пароль');
        if (! is_string($password) || mb_strlen($password) < 12 || $password !== $confirmation) {
            $this->error('Пароли должны совпадать и содержать минимум 12 символов');

            return self::INVALID;
        }
        DB::transaction(function () use ($email, $code, $name, $password, $company): void {
            if (! Role::where('slug', 'super_admin')->exists()) {
                (new RbacSeeder)->run();
            }
            $company ??= Company::create(['name' => $name, 'code' => $code]);
            $user = User::create(['name' => 'Администратор', 'email' => $email, 'password' => $password]);
            $employee = Employee::create(['company_id' => $company->id, 'user_id' => $user->id,
                'employee_no' => 'ADMIN-'.Str::upper(Str::random(10)), 'first_name' => 'Администратор',
                'last_name' => $company->name, 'status' => 'active']);
            $employee->roles()->attach(Role::where('slug', 'super_admin')->value('id'));
        });
        $this->info('Администратор создан. Пароль не выводится и не записывается в журнал.');

        return self::SUCCESS;
    }
}
