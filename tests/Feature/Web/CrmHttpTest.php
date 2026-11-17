<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Company;
use App\Models\CrmActivity;
use App\Models\CrmClient;
use App\Models\CrmContact;
use App\Models\CrmDeal;
use App\Models\CrmLead;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\Role;
use App\Models\User;
use App\Shared\Enums\CrmDealStage;
use App\Shared\Enums\CrmLeadStatus;
use App\Support\Sidebar;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Этап E2 (CRM): гейтинг прав, клиенты/контакты, лиды и конверсия,
 * стадии воронки, активности и межтенантная изоляция.
 */
class CrmHttpTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $adminUser;

    protected Employee $adminEmployee;

    protected CrmClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->company = Company::create(['name' => 'CRM Co', 'code' => 'crmco']);
        $this->adminUser = User::factory()->create(['email' => 'crm-admin@corpms.demo']);
        $this->adminEmployee = $this->makeEmployee($this->company, 'C-1', 'Админ', 'Админов');
        EmployeeRole::create([
            'employee_id' => $this->adminEmployee->id,
            'role_id' => Role::query()->where('slug', 'super_admin')->value('id'),
        ]);
        $this->adminEmployee->update(['user_id' => $this->adminUser->id]);
        $this->adminEmployee->refresh();

        $this->client = CrmClient::create([
            'company_id' => $this->company->id,
            'name' => 'ООО Клиент',
            'kind' => 'company',
            'status' => 'active',
            'owner_id' => $this->adminEmployee->id,
        ]);

        $this->actingAs($this->adminUser);
    }

    protected function makeEmployee(Company $company, string $no, string $first, string $last, ?string $roleSlug = null): Employee
    {
        $employee = Employee::create([
            'company_id' => $company->id,
            'user_id' => User::factory()->create()->id,
            'employee_no' => $no,
            'first_name' => $first,
            'last_name' => $last,
            'hired_at' => '2025-01-01',
            'status' => 'active',
        ]);

        if ($roleSlug !== null) {
            EmployeeRole::create([
                'employee_id' => $employee->id,
                'role_id' => Role::query()->where('slug', $roleSlug)->value('id'),
            ]);
        }

        return $employee;
    }

    /** Гейтинг: без crm.* — 403; super_admin видит раздел в сайдбаре. */
    public function test_permission_gating(): void
    {
        $worker = $this->makeEmployee($this->company, 'W-1', 'Работник', 'Работников', 'employee');
        $workerUser = User::findOrFail($worker->user_id);

        $this->actingAs($workerUser)->get('/crm/clients')->assertForbidden();
        $this->actingAs($workerUser)->get('/crm/leads')->assertForbidden();
        $this->actingAs($workerUser)->get('/crm/deals')->assertForbidden();
        $this->actingAs($workerUser)->get('/crm/activities')->assertForbidden();
        $this->actingAs($workerUser)->post('/crm/clients', ['name' => 'X', 'kind' => 'company'])->assertForbidden();

        $this->actingAs($this->adminUser)->get('/crm/clients')->assertOk()->assertSee('ООО Клиент');
        $this->actingAs($this->adminUser)->get('/crm/leads')->assertOk();
        $this->actingAs($this->adminUser)->get('/crm/deals')->assertOk()->assertSee('Воронка сделок');
        $this->actingAs($this->adminUser)->get('/crm/activities')->assertOk();

        $routes = array_column(Sidebar::build($this->adminUser), 'route');
        $this->assertContains('crm.clients.index', $routes);
        $this->assertContains('crm.deals.index', $routes);

        $workerRoutes = array_column(Sidebar::build($workerUser), 'route');
        $this->assertNotContains('crm.clients.index', $workerRoutes);
    }

    /** Клиенты и контакты: создание, правка, основной контакт, архив. */
    public function test_client_and_contact_crud(): void
    {
        $this->post('/crm/clients', [
            'name' => 'ООО Ромашка',
            'kind' => 'company',
            'inn' => '7701234567',
            'phone' => '+7 900 000-00-00',
            'email' => 'sales@romashka.ru',
        ])->assertSessionHasNoErrors();

        $client = CrmClient::query()->where('name', 'ООО Ромашка')->firstOrFail();
        $this->assertSame('active', $client->status->value);

        // правка карточки
        $this->patch('/crm/clients/'.$client->id, [
            'name' => 'ООО Ромашка Плюс',
            'kind' => 'company',
            'email' => 'new@romashka.ru',
        ])->assertSessionHasNoErrors();

        $this->assertSame('ООО Ромашка Плюс', $client->fresh()->name);

        // контакты: второй «основной» снимает флаг с первого
        $this->post('/crm/clients/'.$client->id.'/contacts', [
            'first_name' => 'Иван',
            'last_name' => 'Петров',
            'position' => 'Директор',
            'is_primary' => 1,
        ])->assertSessionHasNoErrors();

        $this->post('/crm/clients/'.$client->id.'/contacts', [
            'first_name' => 'Мария',
            'last_name' => 'Сидорова',
            'is_primary' => 1,
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, CrmContact::query()->where('client_id', $client->id)->count());
        $this->assertSame(1, CrmContact::query()->where('client_id', $client->id)->where('is_primary', true)->count());

        $primary = CrmContact::query()->where('client_id', $client->id)->where('is_primary', true)->firstOrFail();
        $this->post('/crm/clients/'.$client->id.'/contacts', ['first_name' => 'Третий'])->assertSessionHasNoErrors();

        // карточка клиента рендерится с контактами и сделками
        $this->get('/crm/clients/'.$client->id)
            ->assertOk()
            ->assertSee('ООО Ромашка Плюс')
            ->assertSee('Сидорова');

        // архив и возврат
        $this->post('/crm/clients/'.$client->id.'/toggle')->assertSessionHasNoErrors();
        $this->assertSame('archived', $client->fresh()->status->value);

        $this->post('/crm/clients/'.$client->id.'/toggle')->assertSessionHasNoErrors();
        $this->assertSame('active', $client->fresh()->status->value);

        // удаление контакта
        $contactId = CrmContact::query()->where('client_id', $client->id)->where('first_name', 'Третий')->value('id');
        $this->delete('/crm/clients/'.$client->id.'/contacts/'.$contactId)->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('crm_contacts', ['id' => $contactId]);

        // невалидный e-mail отклоняется
        $this->post('/crm/clients', ['name' => 'Плохой', 'kind' => 'company', 'email' => 'not-an-email'])
            ->assertSessionHasErrors('email');

        $this->assertSame(1, CrmContact::query()->where('first_name', 'Мария')->where('is_primary', true)->count());
        $this->assertSame('Сидорова', $primary->last_name);
    }

    /** Лиды: квалификация, потеря, конверсия в клиента и сделку. */
    public function test_lead_qualification_and_conversion(): void
    {
        $this->post('/crm/leads', [
            'title' => 'Интерес к внедрению',
            'source' => 'web',
            'contact_name' => 'Пётр Иванов',
            'phone' => '+7 900 111-22-33',
            'estimated_amount' => 250000,
        ])->assertSessionHasNoErrors();

        $lead = CrmLead::query()->where('title', 'Интерес к внедрению')->firstOrFail();
        $this->assertSame(CrmLeadStatus::New, $lead->status);

        $this->post('/crm/leads/'.$lead->id.'/qualify')->assertSessionHasNoErrors();
        $this->assertSame(CrmLeadStatus::Qualified, $lead->fresh()->status);

        // конверсия создаёт клиента и сделку
        $this->post('/crm/leads/'.$lead->id.'/convert')->assertSessionHasNoErrors();

        $lead->refresh();
        $this->assertSame(CrmLeadStatus::Converted, $lead->status);
        $this->assertNotNull($lead->client_id);

        $client = CrmClient::query()->findOrFail($lead->client_id);
        $this->assertSame('Интерес к внедрению', $client->name);

        $deal = CrmDeal::query()->where('lead_id', $lead->id)->firstOrFail();
        $this->assertSame(CrmDealStage::New, $deal->stage);
        $this->assertSame(250000.0, (float) $deal->amount);
        $this->assertSame($this->adminEmployee->id, (int) $deal->owner_id);
        $this->assertSame($client->id, (int) $deal->client_id);

        // повторная конверсия отклоняется
        $this->post('/crm/leads/'.$lead->id.'/convert')->assertSessionHasErrors('status');
        $this->assertSame(1, CrmDeal::query()->count());

        // потерянный лид не конвертируется
        $this->post('/crm/leads', ['title' => 'Холодный', 'source' => 'call'])->assertSessionHasNoErrors();
        $cold = CrmLead::query()->where('title', 'Холодный')->firstOrFail();
        $this->post('/crm/leads/'.$cold->id.'/lose')->assertSessionHasNoErrors();
        $this->assertSame(CrmLeadStatus::Lost, $cold->fresh()->status);
        $this->post('/crm/leads/'.$cold->id.'/convert')->assertSessionHasErrors('status');
        $this->assertSame(1, CrmDeal::query()->count());
    }

    /** Стадии воронки: won/lost закрывают сделку, возврат — открывает. */
    public function test_deal_stage_machine(): void
    {
        $this->post('/crm/deals', [
            'title' => 'Поставка ноутбуков',
            'client_id' => $this->client->id,
            'amount' => 500000,
            'stage' => 'new',
            'expected_close_date' => now()->addWeek()->toDateString(),
        ])->assertSessionHasNoErrors();

        $deal = CrmDeal::query()->where('title', 'Поставка ноутбуков')->firstOrFail();
        $this->assertSame(CrmDealStage::New, $deal->stage);
        $this->assertNull($deal->closed_at);

        $this->post('/crm/deals/'.$deal->id.'/move', ['stage' => 'negotiation'])->assertSessionHasNoErrors();
        $this->assertSame(CrmDealStage::Negotiation, $deal->fresh()->stage);
        $this->assertNull($deal->fresh()->closed_at);

        // выиграна: дата закрытия + 100%
        $this->post('/crm/deals/'.$deal->id.'/move', ['stage' => 'won'])->assertSessionHasNoErrors();
        $deal->refresh();
        $this->assertSame(CrmDealStage::Won, $deal->stage);
        $this->assertNotNull($deal->closed_at);
        $this->assertSame(100, $deal->probability);

        // возврат в работу: дата снимается, вероятность — по стадии
        $this->post('/crm/deals/'.$deal->id.'/move', ['stage' => 'proposal'])->assertSessionHasNoErrors();
        $deal->refresh();
        $this->assertNull($deal->closed_at);
        $this->assertSame(CrmDealStage::Proposal->defaultProbability(), $deal->probability);

        // проиграна: 0%
        $this->post('/crm/deals/'.$deal->id.'/move', ['stage' => 'lost'])->assertSessionHasNoErrors();
        $deal->refresh();
        $this->assertNotNull($deal->closed_at);
        $this->assertSame(0, $deal->probability);

        // невалидная стадия
        $this->post('/crm/deals/'.$deal->id.'/move', ['stage' => 'nonsense'])->assertSessionHasErrors('stage');

        // редактирование условий
        $this->patch('/crm/deals/'.$deal->id, [
            'title' => 'Поставка ноутбуков и мониторов',
            'client_id' => $this->client->id,
            'amount' => 640000,
        ])->assertSessionHasNoErrors();

        $this->assertSame(640000.0, (float) $deal->fresh()->amount);

        $this->get('/crm/deals')->assertOk()->assertSee('Поставка ноутбуков и мониторов');
    }

    /** Активности: создание, выполнение, возврат в работу, просрочка. */
    public function test_activity_lifecycle(): void
    {
        $deal = CrmDeal::create([
            'company_id' => $this->company->id,
            'client_id' => $this->client->id,
            'title' => 'Сделка для активности',
            'amount' => 1000,
            'stage' => 'new',
            'probability' => 10,
        ]);

        $this->post('/crm/activities', [
            'kind' => 'call',
            'subject' => 'Позвонить клиенту',
            'due_at' => now()->subDay()->format('Y-m-d\TH:i'),
            'deal_id' => $deal->id,
            'client_id' => $this->client->id,
        ])->assertSessionHasNoErrors();

        $activity = CrmActivity::query()->where('subject', 'Позвонить клиенту')->firstOrFail();
        $this->assertNull($activity->completed_at);
        $this->assertTrue($activity->isOverdue());

        $this->get('/crm/activities?tab=overdue')->assertOk()->assertSee('Позвонить клиенту')->assertSee('просрочена');

        $this->post('/crm/activities/'.$activity->id.'/complete')->assertSessionHasNoErrors();
        $this->assertNotNull($activity->fresh()->completed_at);
        $this->assertFalse($activity->fresh()->isOverdue());

        $this->get('/crm/activities?tab=done')->assertOk()->assertSee('Позвонить клиенту');

        $this->post('/crm/activities/'.$activity->id.'/reopen')->assertSessionHasNoErrors();
        $this->assertNull($activity->fresh()->completed_at);

        // невалидный вид активности
        $this->post('/crm/activities', ['kind' => 'teleport', 'subject' => 'X'])
            ->assertSessionHasErrors('kind');
    }

    /** Межтенантная изоляция: чужие объекты и ссылки из формы — 403. */
    public function test_tenant_isolation(): void
    {
        $otherCompany = Company::create(['name' => 'Other CRM', 'code' => 'othercrm']);
        $foreignClient = CrmClient::create([
            'company_id' => $otherCompany->id,
            'name' => 'Чужой клиент',
            'kind' => 'company',
            'status' => 'active',
        ]);
        $foreignDeal = CrmDeal::create([
            'company_id' => $otherCompany->id,
            'client_id' => $foreignClient->id,
            'title' => 'Чужая сделка',
            'amount' => 10,
            'stage' => 'new',
            'probability' => 10,
        ]);
        $foreignLead = CrmLead::create([
            'company_id' => $otherCompany->id,
            'title' => 'Чужой лид',
            'source' => 'web',
            'status' => 'new',
            'estimated_amount' => 10,
        ]);
        $foreignActivity = CrmActivity::create([
            'company_id' => $otherCompany->id,
            'client_id' => $foreignClient->id,
            'kind' => 'task',
            'subject' => 'Чужая активность',
        ]);

        // прямой доступ по ID
        $this->get('/crm/clients/'.$foreignClient->id)->assertForbidden();
        $this->patch('/crm/clients/'.$foreignClient->id, ['name' => 'Взлом', 'kind' => 'company'])->assertForbidden();
        $this->post('/crm/clients/'.$foreignClient->id.'/toggle')->assertForbidden();
        $this->post('/crm/leads/'.$foreignLead->id.'/qualify')->assertForbidden();
        $this->post('/crm/leads/'.$foreignLead->id.'/convert')->assertForbidden();
        $this->post('/crm/deals/'.$foreignDeal->id.'/move', ['stage' => 'won'])->assertForbidden();
        $this->post('/crm/activities/'.$foreignActivity->id.'/complete')->assertForbidden();

        // ссылки из тела запроса
        $this->post('/crm/deals', [
            'title' => 'Сделка на чужого клиента',
            'client_id' => $foreignClient->id,
            'amount' => 1,
        ])->assertForbidden();

        $this->post('/crm/activities', [
            'kind' => 'task',
            'subject' => 'Активность на чужую сделку',
            'deal_id' => $foreignDeal->id,
        ])->assertForbidden();

        $this->post('/crm/leads', [
            'title' => 'Лид на чужого сотрудника',
            'source' => 'web',
            'owner_id' => 999999,
        ])->assertForbidden();

        // списки не показывают чужие записи
        $this->get('/crm/clients')->assertOk()->assertDontSee('Чужой клиент');
        $this->get('/crm/leads')->assertOk()->assertDontSee('Чужой лид');
        $this->get('/crm/deals')->assertOk()->assertDontSee('Чужая сделка');
        $this->get('/crm/activities')->assertOk()->assertDontSee('Чужая активность');
    }
}
