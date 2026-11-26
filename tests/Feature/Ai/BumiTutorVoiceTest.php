<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AiProvider;
use App\Models\BumiTutorMessage;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\LmsCourse;
use App\Models\LmsLesson;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Bumi как репетитор/коуч урока и голосовой фолбэк для http://corpms.local.
 */
class BumiTutorVoiceTest extends TestCase
{
    use RefreshDatabase;

    protected Company $companyA;

    protected Company $companyB;

    protected User $manager;

    protected Employee $employee;

    protected LmsLesson $lesson;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        config()->set('corpms.ai.base_url', '');
        config()->set('corpms.ai.api_key', '');
        config()->set('corpms.ai.allowed_hosts', ['api.groq.com']);
        config()->set('corpms.bumi.conversation.enabled', true);
        config()->set('corpms.bumi.conversation.stt', 'server');

        $this->companyA = Company::create(['name' => 'Tutor A', 'code' => 'tutor-a']);
        $this->companyB = Company::create(['name' => 'Tutor B', 'code' => 'tutor-b']);

        $employee = $this->employee($this->companyA, 'BT-1', 'Мария', 'Наставник', 'super_admin');
        $this->employee = $employee;
        $this->manager = $employee->user;
        $this->lesson = $this->lesson($this->companyA, 'Организация Shift', 'Смена начинается с проверки присутствия.');
    }

    protected function employee(Company $company, string $no, string $first, string $last, string $roleSlug): Employee
    {
        $employee = Employee::create([
            'company_id' => $company->id,
            'user_id' => User::factory()->create()->id,
            'employee_no' => $no,
            'first_name' => $first,
            'last_name' => $last,
            'status' => 'active',
        ]);
        EmployeeRole::create([
            'employee_id' => $employee->id,
            'role_id' => Role::query()->where('slug', $roleSlug)->value('id'),
        ]);

        return $employee;
    }

    protected function lesson(Company $company, string $title, string $content): LmsLesson
    {
        $course = LmsCourse::create([
            'company_id' => $company->id,
            'title' => 'Курс '.$company->code,
            'status' => 'published',
            'created_by' => $this->employee->id,
        ]);

        return LmsLesson::create([
            'company_id' => $company->id,
            'course_id' => $course->id,
            'title' => $title,
            'content' => $content,
            'created_by' => $this->employee->id,
        ]);
    }

    public function test_lesson_page_shows_bumi_tutor_with_speech_controls_when_conversation_enabled(): void
    {
        $this->actingAs($this->manager)
            ->get('/lms/lessons/'.$this->lesson->id)
            ->assertOk()
            ->assertSee('Bumi · репетитор')
            ->assertSee('data-bumi-role="tutor"', false)
            ->assertSee('data-bumi-role-tool', false)
            ->assertSee('name="lesson_id"', false);
    }

    public function test_tutor_local_fallback_writes_dialog_activity_and_audit(): void
    {
        $this->actingAs($this->manager)
            ->postJson('/lms/lessons/'.$this->lesson->id.'/bumi/ask', [
                'question' => 'Объясни главное',
                'mode' => 'explain',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('source', 'local')
            ->assertJsonPath('mood', 'happy')
            ->assertJsonPath('reason', 'tutor_explain')
            ->assertJsonPath('mode', 'explain');

        self::assertSame(2, BumiTutorMessage::query()->where('lesson_id', $this->lesson->id)->count());
        $this->assertDatabaseHas('lms_activities', [
            'company_id' => $this->companyA->id,
            'course_id' => $this->lesson->course_id,
            'employee_id' => $this->employee->id,
            'type' => 'bumi_tutor',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'company_id' => $this->companyA->id,
            'action' => 'bumi.tutor.asked',
            'object_type' => 'lms_lesson',
            'object_id' => $this->lesson->id,
        ]);
    }

    public function test_hint_mode_counts_hints_and_never_reveals_full_answer_by_default(): void
    {
        config()->set('corpms.bumi.tutor.reveal_answer', false);

        $this->actingAs($this->manager);
        $first = $this->postJson('/lms/lessons/'.$this->lesson->id.'/bumi/ask', [
            'question' => 'Дай подсказку', 'mode' => 'hint',
        ])->assertOk()->json();
        $second = $this->postJson('/lms/lessons/'.$this->lesson->id.'/bumi/ask', [
            'question' => 'Ещё подсказку', 'mode' => 'hint',
        ])->assertOk()->json();

        self::assertSame(1, $first['hint_number']);
        self::assertSame(2, $second['hint_number']);
        self::assertSame('thinking', $second['mood']);
        self::assertSame('tutor_hint', $second['reason']);
        self::assertStringNotContainsString('Полный ответ:', (string) ($second['answer'] ?? ''));
    }

    public function test_tutor_cannot_read_lesson_from_another_company(): void
    {
        $foreign = $this->lesson($this->companyB, 'Чужой урок', 'Секрет другой компании');

        $this->actingAs($this->manager)
            ->postJson('/lms/lessons/'.$foreign->id.'/bumi/ask', ['question' => 'Что здесь?'])
            ->assertForbidden();

        self::assertSame(0, BumiTutorMessage::query()->where('lesson_id', $foreign->id)->count());
    }

    public function test_tutor_can_be_disabled_without_breaking_lesson_page(): void
    {
        config()->set('corpms.bumi.tutor.enabled', false);

        $this->actingAs($this->manager)
            ->get('/lms/lessons/'.$this->lesson->id)
            ->assertOk()
            ->assertDontSee('Bumi — репетитор');

        $this->postJson('/lms/lessons/'.$this->lesson->id.'/bumi/ask', ['question' => 'Объясни'])
            ->assertOk()
            ->assertJsonPath('mood', 'sleepy')
            ->assertJsonPath('reason', 'tutor_paused');
    }

    public function test_voice_transcription_uses_company_provider_and_audits_without_audio_text(): void
    {
        AiProvider::create([
            'company_id' => $this->companyA->id,
            'name' => 'Groq STT',
            'base_url' => 'https://api.groq.com/openai/v1',
            'api_key' => 'sk-test-secret',
            'models' => ['whisper-1'],
            'default_model' => 'whisper-1',
            'is_active' => true,
            'timeout' => 15,
        ]);

        Http::fake([
            'api.groq.com/openai/v1/audio/transcriptions' => Http::response(['text' => 'Проверка присутствия'], 200),
        ]);

        $response = $this->actingAs($this->manager)
            ->post('/bumi/voice/transcribe', [
                'audio' => UploadedFile::fake()->createWithContent('dictation.webm', 'audio-bytes-for-stt'),
                'lang' => 'ru',
            ], ['Accept' => 'application/json']);

        $response->assertOk()->assertJsonPath('ok', true)->assertJsonPath('text', 'Проверка присутствия');

        Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/audio/transcriptions')
            && $request->hasHeader('Authorization', 'Bearer sk-test-secret'));

        $audit = DB::table('audit_logs')->where('action', 'bumi.voice.transcribed')->firstOrFail();
        self::assertSame($this->companyA->id, (int) $audit->company_id);
        self::assertStringNotContainsString('Проверка присутствия', (string) json_encode($audit));
    }

    public function test_voice_transcription_fails_cleanly_when_provider_is_not_configured(): void
    {
        Http::fake();

        $this->actingAs($this->manager)
            ->postJson('/bumi/voice/transcribe', [
                'audio' => UploadedFile::fake()->createWithContent('dictation.webm', 'audio-bytes-for-stt'),
            ])
            ->assertStatus(502)
            ->assertJsonPath('ok', false);

        self::assertSame(0, DB::table('audit_logs')->where('action', 'bumi.voice.transcribed')->count());
    }

    public function test_voice_transcription_rejects_non_audio_payload(): void
    {
        Http::fake();

        $this->actingAs($this->manager)
            ->postJson('/bumi/voice/transcribe', [
                'audio' => UploadedFile::fake()->create('notes.pdf', 4, 'application/pdf'),
            ])
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_http_relay_is_public_but_only_frames_an_allowlisted_parent(): void
    {
        config()->set('corpms.bumi.conversation.relay_url', 'http://localhost:8000');
        config()->set('corpms.bumi.conversation.parent_origins', ['http://corpms.local']);

        $response = $this->get('/bumi/voice/relay?parent='.urlencode('http://corpms.local').'&nonce=abc123456')
            ->assertOk()
            ->assertSee('data-relay-parent="http://corpms.local"', false);

        self::assertStringContainsString(
            "frame-ancestors http://corpms.local",
            (string) $response->headers->get('Content-Security-Policy'),
        );
        self::assertNull($response->headers->get('X-Frame-Options'));
    }

    public function test_http_relay_rejects_unknown_parent_and_missing_configuration(): void
    {
        config()->set('corpms.bumi.conversation.relay_url', 'http://localhost:8000');
        config()->set('corpms.bumi.conversation.parent_origins', ['http://corpms.local']);

        $this->get('/bumi/voice/relay?parent='.urlencode('http://evil.example').'&nonce=abc123456')
            ->assertForbidden();

        config()->set('corpms.bumi.conversation.relay_url', '');
        $this->get('/bumi/voice/relay?parent='.urlencode('http://corpms.local').'&nonce=abc123456')
            ->assertNotFound();
    }

    public function test_mini_bridge_answers_as_tutor_and_keeps_tenant_isolation(): void
    {
        $this->actingAs($this->manager)
            ->postJson('/bumi/ask', [
                'question' => 'Объясни главное',
                'lesson_id' => $this->lesson->id,
                'mode' => 'explain',
            ])
            ->assertOk()
            ->assertJsonStructure(['ok', 'reply', 'reply_html', 'blocks', 'blocks_html', 'speech', 'mood', 'reason'])
            ->assertJsonPath('reason', 'tutor_explain')
            ->assertJsonPath('mode', 'explain');

        $foreign = $this->lesson($this->companyB, 'Чужой урок', 'Секрет другой компании');
        $this->postJson('/bumi/ask', [
            'question' => 'Что здесь?',
            'lesson_id' => $foreign->id,
        ])->assertForbidden();

        self::assertSame(0, BumiTutorMessage::query()->where('lesson_id', $foreign->id)->count());
    }

    public function test_course_owner_scenario_is_saved_audited_notified_and_used_by_tutor(): void
    {
        $owner = $this->employee($this->companyA, 'BT-2', 'Ольга', 'Владелец', 'super_admin');
        $course = $this->lesson->course;
        $course->forceFill(['created_by' => $owner->id])->save();

        $scenario = 'Сначала спроси, что уже ясно, потом дай подсказку по материалам курса.';

        $this->actingAs($this->manager)
            ->post(route('lms.courses.bumi-scenario', $course), ['bumi_scenario' => $scenario])
            ->assertRedirect(route('lms.courses.show', $course));

        $this->assertDatabaseHas('lms_courses', ['id' => $course->id, 'bumi_scenario' => $scenario]);
        $this->assertDatabaseHas('lms_activities', [
            'company_id' => $this->companyA->id,
            'course_id' => $course->id,
            'type' => 'bumi_scenario',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'company_id' => $this->companyA->id,
            'action' => 'lms.course.bumi_scenario.updated',
            'object_type' => 'LmsCourse',
            'object_id' => $course->id,
        ]);
        $this->assertDatabaseHas('attendance_notifications', [
            'recipient_id' => $owner->id,
            'type' => 'lms_bumi_scenario',
        ]);

        $reply = $this->postJson('/bumi/ask', [
            'question' => 'Объясни главное',
            'lesson_id' => $this->lesson->id,
        ])->assertOk()->json();

        self::assertStringContainsString('Сначала спроси', (string) $reply['reply']);
    }
}
