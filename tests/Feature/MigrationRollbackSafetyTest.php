<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/** DB-02: необратимые data-миграции должны падать, а не молча терять данные. */
class MigrationRollbackSafetyTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('irreversibleMigrations')]
    public function test_irreversible_migration_down_throws(string $file, string $message): void
    {
        $migration = require database_path('migrations/'.$file);

        try {
            $migration->down();
            $this->fail('Ожидался RuntimeException для '.$file);
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    /** @return array<string, array{string, string}> */
    public static function irreversibleMigrations(): array
    {
        return [
            'audit secrets' => ['2026_11_16_215115_mask_secret_values_in_audit_logs.php', 'irreversible'],
            'encrypted settings' => ['2026_11_19_000002_encrypt_existing_secret_settings.php', 'irreversible'],
            '2fa and oauth' => ['2026_11_21_000004_add_two_factor_and_oauth_to_users_table.php', 'irreversible'],
        ];
    }
}
