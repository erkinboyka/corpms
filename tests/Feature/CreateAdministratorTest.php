<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdministratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_initial_admin_is_created_with_hashed_password_and_company(): void
    {
        $this->artisan('corpms:admin', ['email' => 'admin@initial.test', '--company-code' => 'initial', '--company-name' => 'Initial'])
            ->expectsQuestion('Пароль администратора (минимум 12 символов)', 'StrongInitialPassword123')
            ->expectsQuestion('Повторите пароль', 'StrongInitialPassword123')->assertExitCode(0);
        $user = User::where('email', 'admin@initial.test')->firstOrFail();
        $this->assertTrue(Hash::check('StrongInitialPassword123', $user->password));
        $this->assertSame('initial', $user->employee->company->code);
        $this->assertSame('super_admin', $user->employee->roles->sole()->slug);
    }

    public function test_existing_account_cannot_be_promoted_by_bootstrap_command(): void
    {
        $user = User::factory()->create(['email' => 'existing@initial.test']);
        $hash = $user->password;
        $this->artisan('corpms:admin', ['email' => $user->email, '--company-code' => 'initial', '--company-name' => 'Initial'])->assertExitCode(2);
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertNull($user->fresh()->employee);
    }
}
