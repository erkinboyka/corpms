<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\AiConversation;
use App\Models\AttendanceRecord;
use App\Models\BiometricDevice;
use App\Models\BiometricEvent;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Shared\Enums\AttendanceStatus;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AI-ассистент (п.68 ТЗ) и live-фид посещаемости (п.30).
 */
class AiAndLiveFeedHttpTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $adminUser;

    protected Employee $adminEmployee;

    protected User $workerUser;

    protected Employee $worker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->company = Company::create(['name' => 'AI Co', 'code' => 'aico']);

        $this->adminUser = User::factory()->create();
        $this->adminEmployee = $this->employee('A-1', 'Админ', 'Айтишников', $this->adminUser->id);
        EmployeeRole::create([
            'employee_id' => $this->adminEmployee->id,
            'role_id' => Role::query()->where('slug', 'super_admin')->value('id'),
        ]);

        $this->workerUser = User::factory()->create();
        $this->worker = $this->employee('W-1', 'Ольга', 'Рядовая', $this->workerUser->id);
        EmployeeRole::create([
            'employee_id' => $this->worker->id,
            'role_id' => Role::query()->where('slug', 'employee')->value('id'),
        ]);

        $this->actingAs($this->adminUser);
    }

    protected function employee(string $no, string $first, string $last, ?int $userId = null): Employee
    {
        return Employee::create([
            'company_id' => $this->company->id,
            'user_id' => $userId ?? User::factory()->create()->id,
            'employee_no' => $no,
            'first_name' => $first,
            'last_name' => $last,
            'status' => 'active',
        ]);
    }

    public function test_bumi_only_signs_the_selected_version_after_confirmation(): void
    {
        $ask = fn () => $this->postJson('/ai/message', ['question' => 'Подпиши документ'])->assertOk();
        $ask()->assertJsonPath('blocks', [])->assertJsonPath('reply', 'Сейчас у вас нет документов, ожидающих вашей подписи.');
        $category = \App\Models\DocumentCategory::create(['name' => 'Акты', 'code' => 'act']);
        $service = app(\App\Domains\Documents\Services\DocumentService::class);
        $signatures = app(\App\Domains\Documents\Services\SignatureService::class);
        foreach ([$this->worker, $this->adminEmployee] as $signer) {
            $doc = $service->create($this->adminEmployee, ['title' => 'Акт '.$signer->id, 'category_id' => $category->id, 'body' => 'Текст версии']);
            $doc->update(['status' => 'approved']);
            $signatures->requestSignatures($this->adminEmployee, $doc->fresh(), [$signer->id]);
        }
        $card = $ask()->assertJsonCount(1, 'blocks')->assertJsonPath('blocks.0.document', $doc->id)
            ->assertJsonPath('blocks.0.version', 1)->json('blocks.0');
        $this->assertDatabaseCount('document_signatures', 0);
        $url = route('documents.signature.sign', [$card['document'], $card['request']]);
        $this->postJson($url)->assertOk();
        $this->postJson($url)->assertUnprocessable();
        $this->assertDatabaseCount('document_signatures', 1);
        $this->assertDatabaseHas('document_signatures', ['document_id' => $doc->id, 'signer_id' => $this->adminEmployee->id]);
        $this->get('/ai')->assertOk()->assertSee('Подписано');
        $ask()->assertJsonPath('blocks', []);
    }

    public function test_template_catalog_exports_all_layouts_with_their_values_and_sources(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $catalog = $this->postJson('/ai/message', ['question' => 'Покажи шаблоны'])->assertOk()->assertJsonPath('blocks.0.type', 'templates');
        self::assertStringContainsString('Брифинг руководства', $catalog->json('blocks_html'));
        $cases = ['Подготовь отчёт' => 'HTML', 'Создай презентацию' => 'PPTX', 'Построй диаграмму' => 'SVG'];
        foreach ($cases as $command => $format) {
            $layouts = [];
            for ($n = 1; $n <= 15; $n++) {
                $response = $this->postJson('/ai/message', ['question' => "$command, шаблон №$n по данным: Склад=4, Офис=7"])
                    ->assertOk()->assertJsonPath('ok', true)->assertJsonPath('blocks.1.format', $format)
                    ->assertJsonPath('blocks.1.template', $n)->assertJsonPath('blocks.2.items.0.label', 'Числа из вашего сообщения');
                $content = $this->get(route('ai.files.download', $response->json('blocks.1.file')))->assertOk()->streamedContent();
                if ($format === 'PPTX') {
                    $copy = tempnam(sys_get_temp_dir(), 'deck');
                    try {
                        file_put_contents($copy, $content);
                        $zip = new \ZipArchive;
                        self::assertTrue($zip->open($copy));
                        $list = simplexml_load_string($zip->getFromName('ppt/presentation.xml'))->xpath('//p:sldId');
                        self::assertGreaterThanOrEqual(4, count($list));
                        $content = '';
                        foreach ($list as $i => $slide) {
                            $xml = $zip->getFromName('ppt/slides/slide'.($i+1).'.xml');
                            self::assertNotFalse(simplexml_load_string($xml));
                            $content .= $xml;
                        }
                        $zip->close();
                    } finally {
                        unlink($copy);
                    }
                } elseif ($format === 'SVG') {
                    self::assertNotFalse(simplexml_load_string($content));
                }
                self::assertStringContainsString('Склад', $content);
                self::assertStringContainsString('Офис', $content);
                self::assertStringContainsString('Числа из вашего сообщения', $content);
                $layouts[] = hash('sha256', $content);
            }
            self::assertCount(15, array_unique($layouts));
        }
    }

    public function test_bumi_proves_answers_and_fills_named_assignee(): void
    {
        $ask = fn ($question) => $this->postJson('/ai/message', compact('question'))->assertOk()->assertJsonPath('ok', true);
        $task = Task::create(['company_id'=>$this->company->id, 'title'=>'Сверить смету', 'number'=>'BM-142',
            'author_id'=>$this->adminEmployee->id, 'status'=>'overdue']);
        $ask('Найди задачу BM-142')->assertJsonPath('blocks.0.items.0.url', route('tasks.show', $task, false));
        $ask('Какие задачи просрочены?')->assertJsonPath('blocks.0.type', 'sources');
        $ask('Создай задачу «Проверить счёт», исполнитель: Ольга Рядовая')->assertJsonPath('blocks.0.assignee', $this->worker->id);
        $ask('Создай задачу «Проверить счёт», исполнитель: Неизвестный')->assertJsonPath('blocks.0.assignee', null);
        $ask('Заполни заявку на отгул завтра, причина: семейные дела')->assertJsonPath('blocks.0.reason','семейные дела');
        $ask('Укажи где находятся документы')->assertJsonPath('blocks.0.guide', true);
        AttendanceRecord::create(['employee_id'=>$this->worker->id, 'date'=>today()->toDateString(),
            'clock_in_at'=>now(), 'late_minutes'=>12, 'status'=>AttendanceStatus::Late]);
        $ask('Кто опоздал сегодня?')->assertJsonPath('blocks.0.rows.0.0','Рядовая Ольга')->assertJsonPath('blocks.1.items.0.url','/attendance');
        $ask('Построй диаграмму, шаблон №6 по данным: А=0, Б=0')->assertJsonPath('blocks.0.type','templates');
    }

    public function test_bumi_forms_and_private_exports(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $ask = fn (string $q) => $this->postJson('/ai/message', ['question' => $q])->assertOk()->assertJsonPath('ok', true);
        $draft = $ask('Создай задачу «Проверить смету» на завтра')->assertJsonPath('blocks.0.title', 'Проверить смету')->json('blocks.0');
        $this->post('/tasks', ['title' => $draft['title'], 'priority' => $draft['priority'], 'deadline' => $draft['deadline'], 'type' => 'task', 'employee_id' => $this->adminEmployee->id])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('tasks', ['title' => 'Проверить смету']);
        $leave = $ask('Заполни заявку на отпуск с 12.12.2026 по 19.12.2026')->assertJsonPath('blocks.0.start', '2026-12-12')->json('blocks.0');
        $this->post('/leaves', ['type' => $leave['kind'], 'from_date' => $leave['start'], 'to_date' => $leave['end']])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('leave_requests', ['employee_id' => $this->adminEmployee->id]);
        $category = \App\Models\DocumentCategory::create(['name' => 'Записки', 'code' => 'note']);
        $doc = $ask('Подготовь документ: План инвентаризации')->assertJsonPath('blocks.0.type', 'document_draft')->json('blocks.0');
        $this->post('/documents', ['title'=>$doc['title'], 'body'=>$doc['body'], 'category_id'=>$category->id])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('documents', ['title' => 'План инвентаризации']);
        $ask('Построй график опозданий за неделю')->assertJsonCount(7, 'blocks.0.data')->assertJsonPath('blocks.0.style', 'line');
        $ask('Построй круговую диаграмму: склад=3, офис=6')->assertJsonPath('blocks.0.style', 'donut');
        foreach (['Создай файл с текстом: План встречи' => ['TXT','План встречи'],
                  'Построй диаграмму: март=15, апрель=29' => ['SVG','апрель'],
                  'Выгрузи таблицу: март=15, апрель=29' => ['CSV','апрель'],
                  'Подготовь отчёт по посещаемости' => ['HTML','Посещаемость']] as $question => [$format,$sample]) {
            $files = collect($ask($question)->json('blocks'));
            $file = $files->firstWhere('type', 'file');
            self::assertSame($format, $file['format']);
            $url = route('ai.files.download', $file['file']);
            self::assertStringContainsString($sample, $this->get($url)->assertOk()->streamedContent());
        }
        EmployeeRole::create(['employee_id' => $this->worker->id, 'role_id' => Role::query()->where('slug', 'super_admin')->value('id')]);
        $this->actingAs($this->workerUser)->get($url)->assertNotFound();
    }

    public function test_bumi_generates_an_image_and_interprets_a_free_form_request(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        config(['corpms.ai.base_url' => 'https://ai.example.test/v1', 'corpms.ai.api_key' => 'test', 'corpms.ai.image_model' => 'test-image']);
        $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/WXkAAAAASUVORK5CYII=';
        Http::fake([
            'ai.example.test/v1/images/generations' => Http::response(['data' => [['b64_json' => $png]]]),
            'ai.example.test/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => "\x60\x60\x60json\n".json_encode(['operation' => ['kind' => 'task','subject' => 'Проверить смету']])."\n\x60\x60\x60"]]]]),
        ]);
        $file = $this->postJson('/ai/message', ['question' => 'Нарисуй постер для выставки'])->assertOk()->assertJsonPath('blocks.0.format', 'PNG')->json('blocks.0.file');
        self::assertSame(base64_decode($png), $this->get(route('ai.files.download', ['file' => $file,'preview' => 1]))->assertOk()->assertHeader('Content-Type','image/png')->streamedContent());
        $this->postJson('/ai/message', ['question' => 'Надо бы занести проверку сметы в список дел'])->assertOk()->assertJsonPath('blocks.0.type', 'task_draft')->assertJsonPath('blocks.0.title','Проверить смету');
        $this->assertDatabaseCount('tasks', 0);
    }

    /* -------------------- AI: локальный аналитик -------------------- */

    public function test_ai_answers_attendance_question_from_facts(): void
    {
        AttendanceRecord::create([
            'employee_id' => $this->worker->id,
            'date' => today(),
            'clock_in_at' => now()->subHours(2),
            'late_minutes' => 15,
            'status' => AttendanceStatus::Late,
        ]);

        $this->post('/ai', ['question' => 'Есть ли опоздания и сколько людей на месте сегодня?'])
            ->assertRedirect()
            ->assertSessionHas('status');

        $conversation = AiConversation::query()->sole();
        self::assertStringContainsStringIgnoringCase('опоздали', (string) $conversation->answer);
        // честный флаг: LLM не настроен → локальный фолбэк → grounded=false (S10)
        self::assertFalse((bool) $conversation->grounded);
        // факты записаны вместе с ответом
        self::assertSame('company', $conversation->query_used['scope']);
        self::assertSame(1, $conversation->query_used['attendance_today']['late']);

        $this->assertDatabaseHas('audit_logs', ['action' => 'ai.asked']);

        // история видна на странице
        $this->get('/ai')
            ->assertOk()
            ->assertSee('Есть ли опоздания')
            ->assertSee('опоздали 1')
            ->assertSee('grounded');
    }

    public function test_ai_answers_tasks_question(): void
    {
        Task::create([
            'company_id' => $this->company->id,
            'title' => 'Просроченная задача',
            'number' => 'T-AI-1',
            'author_id' => $this->adminEmployee->id,
            'status' => 'overdue',
        ])->assignments()->create([
            'assignee_type' => 'employee',
            'employee_id' => $this->worker->id,
            'assignment_status' => 'accepted',
        ]);

        $this->post('/ai', ['question' => 'Сколько просроченных задач?'])
            ->assertRedirect();

        $answer = (string) AiConversation::query()->sole()->answer;
        self::assertStringContainsStringIgnoringCase('просрочено', $answer);
        self::assertStringContainsString('1', $answer);
    }

    public function test_bumi_history_formats_only_assistant_markdown_and_blocks_unsafe_markup(): void
    {
        AiConversation::create([
            'employee_id' => $this->adminEmployee->id,
            'question' => '**Вопрос без форматирования**',
            'answer' => "**Готово**\n\n- Первый шаг\n- Используйте `Bumi`\n\n"
                ."[Небезопасная ссылка](javascript:invalid)\n\n<b>Сырой HTML</b>\n\n![Картинка](https://example.test/pixel.png)",
        ]);

        $this->get('/ai')
            ->assertOk()
            ->assertSee('Bumi')
            ->assertSee('data-bumi-face', false)
            ->assertSee('**Вопрос без форматирования**')
            ->assertSee('<strong>Готово</strong>', false)
            ->assertSee('<li>Первый шаг</li>', false)
            ->assertSee('<code>Bumi</code>', false)
            ->assertDontSee('javascript:invalid', false)
            ->assertDontSee('<b>Сырой HTML</b>', false)
            ->assertDontSee('https://example.test/pixel.png', false);
    }

    public function test_ai_validation_rejects_short_question(): void
    {
        $this->post('/ai', ['question' => 'ok'])
            ->assertRedirect()
            ->assertSessionHasErrors(['question']);

        self::assertSame(0, AiConversation::query()->count());
    }

    /* -------------------- AI: настроенный LLM -------------------- */

    public function test_ai_uses_configured_llm_and_stays_grounded(): void
    {
        config([
            'corpms.ai.base_url' => 'https://llm.example.com/v1',
            'corpms.ai.api_key' => 'secret-key',
        ]);

        Http::fake([
            'llm.example.com/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'По фактам: сегодня 1 опоздание, 0 на месте из 2 сотрудников.']],
                ],
            ]),
        ]);

        $this->post('/ai', ['question' => 'Какая посещаемость?'])->assertRedirect();

        $conversation = AiConversation::query()->sole();
        self::assertStringContainsString('По фактам', (string) $conversation->answer);
        self::assertTrue((bool) $conversation->grounded);

        // LLM получил факты в системном сообщении
        Http::assertSent(function ($request): bool {
            $body = $request->data();
            $messages = $body['messages'];

            return str_contains((string) $messages[1]['content'], 'Факты')
                && str_contains((string) $messages[1]['content'], '"scope":"company"')
                && str_contains((string) $messages[2]['content'], 'посещаемост');
        });
    }

    public function test_ai_denied_without_permission(): void
    {
        $this->actingAs($this->workerUser);

        $this->get('/ai')->assertForbidden();
        $this->post('/ai', ['question' => 'сколько людей на месте?'])->assertForbidden();

        self::assertSame(0, AiConversation::query()->count());
    }

    /* -------------------- S10: история диалога + честный grounded -------------------- */

    public function test_second_llm_question_includes_dialogue_history_in_context(): void
    {
        config([
            'corpms.ai.base_url' => 'https://llm.example.com/v1',
            'corpms.ai.api_key' => 'secret-key',
        ]);

        Http::fake([
            'llm.example.com/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'Первый ответ ассистента.']],
                ],
            ]),
        ]);

        // первый вопрос — история ещё пуста
        $this->post('/ai', ['question' => 'Сколько сотрудников в компании?'])->assertRedirect();

        Http::fake([
            'llm.example.com/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'Второй ответ с учётом контекста.']],
                ],
            ]),
        ]);

        // второй вопрос: в контексте должны быть первая реплика и первый ответ
        $this->post('/ai', ['question' => 'А из них сколько опоздали?'])->assertRedirect();

        Http::assertSent(function ($request): bool {
            $messages = $request->data()['messages'];
            $roles = array_column($messages, 'role');
            $contents = array_column($messages, 'content');

            $hasFirstQuestion = in_array('Сколько сотрудников в компании?', $contents, true);
            $hasFirstAnswer = in_array('Первый ответ ассистента.', $contents, true);
            $lastIsSecondQuestion = $messages[array_key_last($messages)]['content'] === 'А из них сколько опоздали?';

            // первая реплика идёт раньше второй (user → assistant → user)
            $firstIdx = array_search('Сколько сотрудников в компании?', $contents, true);
            $secondIdx = array_search('А из них сколько опоздали?', $contents, true);
            $orderOk = $firstIdx !== false && $secondIdx !== false && $firstIdx < $secondIdx;
            $rolesOk = $roles[$firstIdx] === 'user' && $roles[$secondIdx] === 'user';

            return $hasFirstQuestion && $hasFirstAnswer && $lastIsSecondQuestion && $orderOk && $rolesOk;
        });
    }

    public function test_grounded_is_false_when_llm_fails_over_to_local_fallback(): void
    {
        config([
            'corpms.ai.base_url' => 'https://llm.example.com/v1',
            'corpms.ai.api_key' => 'secret-key',
        ]);

        // LLM настроен, но отвечает ошибкой → фолбэк на локальный анализатор
        Http::fake([
            'llm.example.com/v1/chat/completions' => Http::response(['error' => 'boom'], 500),
        ]);

        $this->post('/ai', ['question' => 'Есть ли опоздания сегодня?'])->assertRedirect();

        $conversation = AiConversation::query()->sole();
        self::assertNotEmpty((string) $conversation->answer, 'Фолбэк должен дать ответ');
        self::assertFalse((bool) $conversation->grounded, 'При фолбэке с LLM grounded должен быть false');
    }

    public function test_panel_message_grounded_flag_reflects_llm_or_fallback(): void
    {
        // LLM не настроен → панель отвечает локально → grounded=false
        $this->postJson('/ai/message', ['question' => 'Сколько просроченных задач?'])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $conversation = AiConversation::query()->orderByDesc('id')->first();
        self::assertNotNull($conversation);
        self::assertFalse((bool) $conversation->grounded, 'Панель без LLM — локальный ответ, grounded=false');

        // LLM настроен и ответил → grounded=true
        config([
            'corpms.ai.base_url' => 'https://llm.example.com/v1',
            'corpms.ai.api_key' => 'secret-key',
        ]);
        Http::fake([
            'llm.example.com/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'Ответ панели от LLM.']],
                ],
            ]),
        ]);

        $this->postJson('/ai/message', ['question' => 'Какая численность?'])->assertOk();

        $conversation = AiConversation::query()->orderByDesc('id')->first();
        self::assertTrue((bool) $conversation->grounded, 'Ответ LLM — grounded=true');
        self::assertSame('Ответ панели от LLM.', (string) $conversation->answer);
    }

    /* -------------------- Live-фид -------------------- */

    public function test_live_feed_returns_events_and_counters(): void
    {
        AttendanceRecord::create([
            'employee_id' => $this->worker->id,
            'date' => today(),
            'clock_in_at' => now()->subHour(),
            'status' => AttendanceStatus::OnTime,
        ]);

        $device = $this->device();
        BiometricEvent::create([
            'device_id' => $device->id,
            'employee_id' => $this->worker->id,
            'event_uuid' => 'feed-evt-1',
            'occurred_at' => now(),
            'direction' => 'in',
            'liveness_passed' => true,
            'liveness_required' => false,
            'raw_payload' => [],
            'processing_status' => 'processed',
        ]);

        $response = $this->getJson('/attendance/live/feed');

        $response->assertOk()
            ->assertJsonPath('events.0.employee', 'Рядовая')
            ->assertJsonPath('events.0.direction', 'in')
            ->assertJsonPath('records.0.employee', 'Рядовая Ольга')
            ->assertJsonPath('counters.on_time', 1);
    }

    public function test_live_feed_denied_without_permission(): void
    {
        $this->actingAs($this->workerUser);
        $this->getJson('/attendance/live/feed')->assertForbidden();
    }

    protected function device(): BiometricDevice
    {
        return BiometricDevice::create([
            'company_id' => $this->company->id,
            'name' => 'Терминал feed',
            'serial_number' => 'SN-FEED-1',
            'device_type' => 'face_terminal',
            'vendor' => 'generic',
            'direction' => 'both',
            'status' => 'online',
            'api_token_hash' => hash('sha256', 'x'),
        ]);
    }
}
