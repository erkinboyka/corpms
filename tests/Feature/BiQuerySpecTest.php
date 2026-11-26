<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\DashboardGen\QuerySpec\BiQueryCompiler;
use App\Domains\DashboardGen\QuerySpec\BiQuerySpec;
use App\Domains\DashboardGen\Services\WidgetQueryRunner;
use App\Domains\DashboardGen\Services\WidgetShapeMapper;
use App\Models\Company;
use App\Models\CrmClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/** Безопасный компилятор BiQuerySpec → параметризованный SQL. */
final class BiQuerySpecTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array<string, array{type: string}>> */
    private function schema(): array
    {
        return [
            'crm_clients' => [
                'id' => ['type' => 'integer'],
                'company_id' => ['type' => 'integer'],
                'name' => ['type' => 'varchar'],
                'status' => ['type' => 'varchar'],
            ],
            'crm_deals' => [
                'id' => ['type' => 'integer'],
                'company_id' => ['type' => 'integer'],
                'client_id' => ['type' => 'integer'],
                'amount' => ['type' => 'decimal'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function rawSpec(): array
    {
        return [
            'queries' => [
                'main' => [
                    'from' => 'crm_clients',
                    'alias' => 'c',
                    'select' => [
                        ['expr' => 'column', 'column' => 'c.name', 'as' => 'label'],
                        ['expr' => 'count', 'as' => 'value'],
                    ],
                    'where' => [
                        ['column' => 'c.status', 'op' => '=', 'value' => 'active'],
                    ],
                    'group_by' => ['label'],
                    'order_by' => [['column' => 'value', 'dir' => 'desc']],
                    'limit' => 10,
                ],
            ],
        ];
    }

    public function test_compiler_queries_identifiers_and_binds_literals(): void
    {
        $compiled = (new BiQueryCompiler($this->schema()))->compile(
            new BiQuerySpec($this->rawSpec(), $this->schema(), 'sqlite'),
        );

        $sql = $compiled['queries']['main'];

        self::assertStringContainsString('SELECT "c"."name" AS "label"', $sql);
        self::assertStringContainsString('FROM "crm_clients" AS "c"', $sql);
        self::assertStringContainsString('"c"."company_id" = :company_id', $sql);
        self::assertStringContainsString('"c"."status" = :p1', $sql);
        self::assertStringNotContainsString("'active'", $sql);
        self::assertSame(['p1' => 'active'], $compiled['bindings']);
        self::assertSame('bi_query_spec_v1', $compiled['compiler']);
    }

    public function test_compiler_rejects_columns_outside_whitelist(): void
    {
        $raw = $this->rawSpec();
        $raw['queries']['main']['select'][0]['column'] = 'c.secret_password';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('secret_password');

        (new BiQueryCompiler($this->schema()))->compile(
            new BiQuerySpec($raw, $this->schema(), 'sqlite'),
        );
    }

    public function test_compiler_rejects_unknown_table(): void
    {
        $raw = $this->rawSpec();
        $raw['queries']['main']['from'] = 'users';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('белый список');

        (new BiQueryCompiler($this->schema()))->compile(
            new BiQuerySpec($raw, $this->schema(), 'sqlite'),
        );
    }

    public function test_runner_executes_compiled_spec_with_tenant_isolation(): void
    {
        $company = Company::create(['name' => 'Our Co', 'code' => 'ourco']);
        $other = Company::create(['name' => 'Other Co', 'code' => 'otherco']);

        CrmClient::create([
            'company_id' => $company->id,
            'name' => 'Наш клиент',
            'kind' => 'company',
            'status' => 'active',
        ]);
        CrmClient::create([
            'company_id' => $other->id,
            'name' => 'Чужой клиент',
            'kind' => 'company',
            'status' => 'active',
        ]);
        CrmClient::create([
            'company_id' => $company->id,
            'name' => 'Архивный клиент',
            'kind' => 'company',
            'status' => 'archived',
        ]);

        $schema = $this->schema();
        $compiled = (new BiQueryCompiler($schema))->compile(
            new BiQuerySpec($this->rawSpec(), $schema, 'sqlite'),
        );

        $result = (new WidgetQueryRunner(
            connection: DB::connection(),
            companyId: $company->id,
        ))->run([
            'queries' => $compiled['queries'],
            'bindings' => $compiled['bindings'],
            'shape' => WidgetShapeMapper::SHAPE_SERIES_VALUES,
            'compiler' => $compiled['compiler'],
        ], 'pie');

        self::assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        self::assertSame(['Наш клиент'], $result['data']['labels']);
        self::assertSame([1], $result['data']['series']);
    }

    public function test_compiler_replaces_foreign_company_literal_with_tenant_placeholder(): void
    {
        $company = Company::create(['name' => 'Our Co', 'code' => 'ourco']);
        $other = Company::create(['name' => 'Other Co', 'code' => 'otherco']);

        CrmClient::create([
            'company_id' => $company->id,
            'name' => 'Наш клиент',
            'kind' => 'company',
            'status' => 'active',
        ]);
        CrmClient::create([
            'company_id' => $other->id,
            'name' => 'Чужой клиент',
            'kind' => 'company',
            'status' => 'active',
        ]);

        $raw = $this->rawSpec();
        $raw['queries']['main']['where'] = [
            ['column' => 'c.company_id', 'op' => '=', 'value' => $other->id],
        ];

        $schema = $this->schema();
        $compiled = (new BiQueryCompiler($schema))->compile(
            new BiQuerySpec($raw, $schema, 'sqlite'),
        );

        $sql = $compiled['queries']['main'];
        self::assertStringContainsString('"c"."company_id" = :company_id', $sql);
        self::assertStringContainsString('"c"."company_id" = :p1', $sql);
        self::assertSame($other->id, $compiled['bindings']['p1']);
        self::assertArrayNotHasKey('company_id', $compiled['bindings']);

        $result = (new WidgetQueryRunner(companyId: $company->id))->run([
            'queries' => $compiled['queries'],
            'bindings' => $compiled['bindings'],
            'shape' => WidgetShapeMapper::SHAPE_SERIES_VALUES,
        ], 'pie');

        self::assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        self::assertSame([], $result['data']['labels']);
        self::assertNotContains('Чужой клиент', $result['data']['labels']);
    }

    public function test_non_equality_company_filter_does_not_disable_tenant_condition(): void
    {
        $company = Company::create(['name' => 'Our Co', 'code' => 'ourco']);
        $other = Company::create(['name' => 'Other Co', 'code' => 'otherco']);

        CrmClient::create([
            'company_id' => $company->id,
            'name' => 'Наш клиент',
            'kind' => 'company',
            'status' => 'active',
        ]);
        CrmClient::create([
            'company_id' => $other->id,
            'name' => 'Чужой клиент',
            'kind' => 'company',
            'status' => 'active',
        ]);

        foreach ([
            ['op' => '!=', 'value' => $other->id],
            ['op' => 'is null'],
            ['op' => 'in', 'value' => [$other->id]],
        ] as $condition) {
            $raw = $this->rawSpec();
            $raw['queries']['main']['where'] = [
                ['column' => 'c.company_id'] + $condition,
            ];

            $schema = $this->schema();
            $compiled = (new BiQueryCompiler($schema))->compile(
                new BiQuerySpec($raw, $schema, 'sqlite'),
            );

            self::assertStringContainsString('"c"."company_id" = :company_id', $compiled['queries']['main']);

            $result = (new WidgetQueryRunner(companyId: $company->id))->run([
                'queries' => $compiled['queries'],
                'bindings' => $compiled['bindings'],
                'shape' => WidgetShapeMapper::SHAPE_SERIES_VALUES,
            ], 'pie');

            self::assertTrue($result['ok'], (string) ($result['error'] ?? ''));
            self::assertNotContains('Чужой клиент', $result['data']['labels']);
        }
    }

    public function test_legacy_string_queries_still_work(): void
    {
        $raw = [
            'queries' => [
                'main' => 'SELECT name AS label, COUNT(*) AS value FROM crm_clients '
                    .'WHERE company_id = :company_id GROUP BY name',
            ],
        ];

        $spec = new BiQuerySpec($raw, $this->schema(), 'sqlite');
        $compiled = (new BiQueryCompiler($this->schema()))->compile($spec);

        self::assertFalse($spec->isStructured());
        self::assertSame($raw['queries']['main'], $compiled['queries']['main']);
        self::assertSame([], $compiled['bindings']);
    }
}
