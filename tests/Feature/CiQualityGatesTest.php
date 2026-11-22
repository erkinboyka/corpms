<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/** TEST-05: обязательные quality-gate декларации в CI. */
final class CiQualityGatesTest extends TestCase
{
    public function test_ci_contains_style_frontend_coverage_and_migration_gates(): void
    {
        $ci = (string) file_get_contents(base_path('.github/workflows/ci.yml'));

        self::assertStringContainsString('php tools/verify-pint-baseline.php', $ci);
        self::assertStringContainsString('npm ci', $ci);
        self::assertStringContainsString('npm run build', $ci);
        self::assertStringContainsString('npm audit --audit-level=high', $ci);
        self::assertStringContainsString('coverage: pcov', $ci);
        self::assertStringContainsString('php tools/verify-coverage.php', $ci);
        self::assertStringContainsString('php artisan migrate:fresh --force', $ci);
        self::assertStringContainsString('--do-not-cache-result', $ci);
    }

    public function test_pint_baseline_is_committed_and_valid(): void
    {
        $path = base_path('tools/pint-baseline.json');
        self::assertFileExists($path);

        $data = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('files', $data);
        self::assertIsArray($data['files']);
        foreach ($data['files'] as $file) {
            self::assertIsString($file);
        }

        // Пустая база после полного форматирования означает строгий gate:
        // любое новое нарушение попадёт в $new и вызовет exit(1).
        $gate = (string) file_get_contents(base_path('tools/verify-pint-baseline.php'));
        self::assertStringContainsString('$new !== []', $gate);
        self::assertStringContainsString('exit(1)', $gate);
    }
}
