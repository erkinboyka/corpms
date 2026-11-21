<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\LmsAssignment;
use App\Models\LmsCourse;
use App\Models\LmsLesson;
use App\Models\LmsMeeting;
use App\Models\LmsWhiteboard;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LMS: курсы, прогресс, вайтборд, WebRTC-сигналинг, доска практики и Пульс.
 * Проверяем и БД, и HTTP-контракт; tenant isolation — отдельным сценарием.
 */
class LmsHttpTest extends TestCase
{
    use RefreshDatabase;

    protected Company $companyA;

    protected Company $companyB;

    protected User $manager;

    protected Employee $managerEmployee;

    protected User $learner;

    protected Employee $learnerEmployee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->companyA = Company::create(['name' => 'LMS A', 'code' => 'lms-a']);
        $this->companyB = Company::create(['name' => 'LMS B', 'code' => 'lms-b']);

        $this->managerEmployee = $this->employee($this->companyA, 'LM-1', 'Мария', 'Наставник', null, 'super_admin');
        $this->manager = $this->managerEmployee->user;

        $this->learnerEmployee = $this->employee($this->companyA, 'LM-2', 'Иван', 'Ученик');
        $this->learner = $this->learnerEmployee->user;

        $this->actingAs($this->manager);
    }

    protected function employee(Company $company, string $no, string $first, string $last, ?int $userId = null, ?string $roleSlug = 'employee'): Employee
    {
        $employee = Employee::create([
            'company_id' => $company->id,
            'user_id' => $userId ?? User::factory()->create()->id,
            'employee_no' => $no,
            'first_name' => $first,
            'last_name' => $last,
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

    public function test_manager_can_create_course_with_board_and_lesson(): void
    {
        $this->post('/lms/courses', [
            'title' => 'Онбординг',
            'summary' => 'Знакомство с командой',
            'publish' => 1,
        ])->assertRedirectContains('/lms/courses/');

        $course = LmsCourse::query()->where('title', 'Онбординг')->firstOrFail();
        $this->assertDatabaseHas('lms_whiteboards', [
            'company_id' => $this->companyA->id,
            'course_id' => $course->id,
        ]);
        $this->assertDatabaseHas('lms_activities', [
            'company_id' => $this->companyA->id,
            'course_id' => $course->id,
            'type' => 'course',
        ]);

        $this->post('/lms/courses/'.$course->id.'/lessons', [
            'title' => 'Первый день',
            'content' => 'Правила команды',
            'duration_min' => 15,
        ])->assertRedirectContains('/lms/lessons/');

        $lesson = LmsLesson::query()->where('title', 'Первый день')->firstOrFail();
        $this->assertSame($course->id, $lesson->course_id);
        $this->assertDatabaseHas('lms_whiteboards', [
            'company_id' => $this->companyA->id,
            'course_id' => $course->id,
            'lesson_id' => $lesson->id,
        ]);

        $this->get('/lms/courses/'.$course->id)->assertOk()->assertSee('Онбординг');
        $this->get('/lms/lessons/'.$lesson->id)->assertOk()->assertSee('Первый день');
    }

    public function test_learner_enrolls_and_progress_is_recalculated(): void
    {
        $course = LmsCourse::create([
            'company_id' => $this->companyA->id,
            'title' => 'Курс прогресса',
            'status' => 'published',
            'created_by' => $this->managerEmployee->id,
        ]);
        $lesson = LmsLesson::create([
            'company_id' => $this->companyA->id,
            'course_id' => $course->id,
            'title' => 'Урок 1',
            'sort_order' => 1,
            'is_required' => true,
        ]);

        $this->actingAs($this->learner)
            ->post('/lms/courses/'.$course->id.'/enroll')
            ->assertRedirect();

        $this->assertDatabaseHas('lms_enrollments', [
            'company_id' => $this->companyA->id,
            'course_id' => $course->id,
            'employee_id' => $this->learnerEmployee->id,
        ]);

        $this->post('/lms/lessons/'.$lesson->id.'/progress')->assertRedirect();

        $this->assertDatabaseHas('lms_lesson_progress', [
            'company_id' => $this->companyA->id,
            'lesson_id' => $lesson->id,
            'status' => 'completed',
        ]);
        $this->assertDatabaseHas('lms_enrollments', [
            'course_id' => $course->id,
            'employee_id' => $this->learnerEmployee->id,
            'progress_percent' => 100,
            'status' => 'completed',
        ]);
    }

    public function test_whiteboard_draw_poll_erase_and_presence(): void
    {
        $board = LmsWhiteboard::create([
            'company_id' => $this->companyA->id,
            'title' => 'Доска теста',
            'created_by' => $this->managerEmployee->id,
        ]);

        $this->get('/lms/whiteboards/'.$board->id)->assertOk()->assertSee('Доска теста');

        $this->postJson('/lms/whiteboards/'.$board->id.'/elements', [
            'type' => 'path',
            'payload' => ['points' => [['x' => 10, 'y' => 10], ['x' => 40, 'y' => 40]]],
            'color' => '#206bc4',
            'width' => 3,
        ])->assertCreated()->assertJsonPath('ok', true);

        $this->postJson('/lms/whiteboards/'.$board->id.'/elements', [
            'type' => 'sticky',
            'payload' => ['x' => 20, 'y' => 20, 'w' => 180, 'h' => 90, 'text' => 'Идея'],
        ])->assertCreated();

        $elements = $this->getJson('/lms/whiteboards/'.$board->id.'/elements?since=0')
            ->assertOk()->assertJsonPath('ok', true)->json('elements');
        $this->assertCount(2, $elements);
        $this->assertSame('path', $elements[0]['type']);
        $this->assertSame('sticky', $elements[1]['type']);

        $lastId = (int) $elements[1]['id'];
        $this->assertCount(0, $this->getJson('/lms/whiteboards/'.$board->id.'/elements?since='.$lastId)->json('elements'));

        $this->deleteJson('/lms/whiteboards/'.$board->id.'/elements/'.$lastId)->assertOk();
        $this->assertDatabaseHas('lms_whiteboard_elements', ['id' => $lastId, 'is_erased' => 1]);

        $this->postJson('/lms/whiteboards/'.$board->id.'/presence', ['x' => 0.5, 'y' => 0.25])->assertOk();
        $this->getJson('/lms/whiteboards/'.$board->id.'/presence')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('presence.0.employee_id', $this->managerEmployee->id);
    }

    public function test_meeting_uses_database_signaling_and_participant_presence(): void
    {
        $this->post('/lms/meetings', [
            'title' => 'Разбор',
            'course_id' => null,
        ])->assertRedirectContains('/lms/meetings/');

        $meeting = LmsMeeting::query()->where('title', 'Разбор')->firstOrFail();
        $this->get('/lms/meetings/'.$meeting->id)->assertOk()->assertSee('Разбор');

        $this->postJson('/lms/meetings/'.$meeting->id.'/join')->assertOk()->assertJsonPath('role', 'host');
        $this->assertDatabaseHas('lms_meetings', ['id' => $meeting->id, 'status' => 'live']);

        $this->actingAs($this->learner)
            ->postJson('/lms/meetings/'.$meeting->id.'/join')
            ->assertOk()
            ->assertJsonPath('role', 'participant');

        $this->postJson('/lms/meetings/'.$meeting->id.'/signals', [
            'type' => 'offer',
            'to_employee_id' => $this->managerEmployee->id,
            'payload' => ['sdp' => ['type' => 'offer', 'sdp' => 'v=0']],
        ])->assertCreated()->assertJsonPath('ok', true);

        $signals = $this->actingAs($this->manager)
            ->getJson('/lms/meetings/'.$meeting->id.'/signals?since=0')
            ->assertOk()
            ->json('signals');
        $this->assertCount(1, $signals);
        $this->assertSame('offer', $signals[0]['type']);
        $this->assertSame($this->learnerEmployee->id, $signals[0]['from']);

        $participants = $this->getJson('/lms/meetings/'.$meeting->id.'/participants')->json('participants');
        $this->assertCount(2, $participants);

        $this->postJson('/lms/meetings/'.$meeting->id.'/leave')->assertOk();
        $this->assertDatabaseHas('lms_meeting_participants', [
            'meeting_id' => $meeting->id,
            'employee_id' => $this->managerEmployee->id,
            'status' => 'left',
        ]);
    }

    public function test_assignment_board_submit_and_review(): void
    {
        $course = LmsCourse::create([
            'company_id' => $this->companyA->id,
            'title' => 'Курс практики',
            'status' => 'published',
            'created_by' => $this->managerEmployee->id,
        ]);

        $this->post('/lms/assignments', [
            'title' => 'Сделать схему',
            'description' => 'Нарисовать архитектуру',
            'course_id' => $course->id,
            'max_score' => 50,
        ])->assertRedirect('/lms/assignments');

        $assignment = LmsAssignment::query()->where('title', 'Сделать схему')->firstOrFail();
        $this->actingAs($this->learner)
            ->post('/lms/assignments/'.$assignment->id.'/submit', [
                'body' => 'Готово',
                'link' => 'https://example.test/schema',
            ])
            ->assertRedirect();

        $submission = $assignment->submissions()->firstOrFail();
        $this->assertSame('submitted', $submission->status->value);

        $this->get('/lms/assignments')->assertOk()->assertSee('На проверке');

        $this->actingAs($this->manager)
            ->post('/lms/assignments/'.$submission->id.'/review', [
                'status' => 'reviewed',
                'score' => 45,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('lms_submissions', [
            'id' => $submission->id,
            'status' => 'reviewed',
            'score' => 45,
            'reviewed_by' => $this->managerEmployee->id,
        ]);
    }

    public function test_tenant_isolation_and_permission_gate(): void
    {
        $foreignCourse = LmsCourse::create([
            'company_id' => $this->companyB->id,
            'title' => 'Чужой курс',
            'status' => 'published',
        ]);
        $foreignBoard = LmsWhiteboard::create([
            'company_id' => $this->companyB->id,
            'title' => 'Чужая доска',
        ]);
        $foreignMeeting = LmsMeeting::create([
            'company_id' => $this->companyB->id,
            'title' => 'Чужой созвон',
            'room' => 'foreign-room',
        ]);

        $this->get('/lms/courses/'.$foreignCourse->id)->assertForbidden();
        $this->get('/lms/whiteboards/'.$foreignBoard->id)->assertForbidden();
        $this->get('/lms/meetings/'.$foreignMeeting->id)->assertForbidden();
        $this->post('/lms/courses/'.$foreignCourse->id.'/enroll')->assertForbidden();
        $this->getJson('/lms/whiteboards/'.$foreignBoard->id.'/elements')->assertForbidden();

        $outsider = $this->employee($this->companyA, 'LM-3', 'Без', 'Прав', null, null);
        $this->actingAs($outsider->user)->get('/lms')->assertForbidden();

        $this->actingAs($this->learner)->get('/lms')->assertOk()->assertSee('Обучение');
    }

    public function test_pulse_events_endpoint_returns_tenant_activity(): void
    {
        $this->actingAs($this->manager)
            ->post('/lms/courses', [
                'title' => 'Курс пульса',
                'publish' => 1,
            ])
            ->assertRedirect();

        $course = LmsCourse::query()->where('title', 'Курс пульса')->firstOrFail();

        $this->actingAs($this->learner)
            ->getJson('/lms/pulse/events?since=0')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonFragment(['course_id' => $course->id, 'type' => 'course']);

        $this->get('/lms/pulse')->assertOk()->assertSee('Карта активности');
    }
}
