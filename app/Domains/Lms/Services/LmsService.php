<?php

declare(strict_types=1);

namespace App\Domains\Lms\Services;

use App\Domains\Audit\Services\AuditService;
use App\Models\Employee;
use App\Models\LmsActivity;
use App\Models\LmsAssignment;
use App\Models\LmsCourse;
use App\Models\LmsEnrollment;
use App\Models\LmsLesson;
use App\Models\LmsLessonProgress;
use App\Models\LmsMeeting;
use App\Models\LmsMeetingParticipant;
use App\Models\LmsSignal;
use App\Models\LmsSubmission;
use App\Models\LmsWhiteboard;
use App\Models\LmsWhiteboardElement;
use App\Models\LmsWhiteboardPresence;
use App\Models\User;
use App\Shared\Enums\LmsAssignmentStatus;
use App\Shared\Enums\LmsCourseStatus;
use App\Shared\Enums\LmsElementType;
use App\Shared\Enums\LmsEnrollmentStatus;
use App\Shared\Enums\LmsMeetingStatus;
use App\Shared\Enums\LmsProgressStatus;
use App\Shared\Enums\LmsSignalType;
use App\Shared\Enums\LmsSubmissionStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ДоменTraining: курсы, прогресс, вайтборд, WebRTC-сигналинг и «Пульс Бumi».
 *
 * Все записи хранят company_id, поэтому tenant-isolation работает без
 * дополнительных исключений. WebRTC-сигналинг намеренно живёт в БД: офису
 * не нужен внешний WebSocket-провайдер, а надёжность выше за счёт polling.
 */
class LmsService
{
    public function __construct(private readonly AuditService $audit) {}

    private function assertCompany(Employee $me, mixed $companyId): void
    {
        abort_unless($companyId !== null && (int) $companyId === (int) $me->company_id, 403);
    }

    /** Public tenant check for read-only pages that do not go through a mutation. */
    public function assertTenant(Employee $me, mixed $companyId): void
    {
        $this->assertCompany($me, $companyId);
    }

    private function employeeName(?Employee $employee): string
    {
        if (! $employee) {
            return __('Система');
        }

        return trim($employee->last_name.' '.$employee->first_name) ?: $employee->employee_no;
    }

    /** @return array{courses:Collection,enrollments:Collection,stats:array,activities:array,tips:array} */
    public function dashboard(Employee $me): array
    {
        $courses = LmsCourse::query()
            ->where('company_id', $me->company_id)
            ->withCount(['lessons', 'enrollments'])
            ->with(['enrollments' => fn ($q) => $q->where('employee_id', $me->id)])
            ->latest()
            ->limit(24)
            ->get();

        $enrollments = LmsEnrollment::query()
            ->where('company_id', $me->company_id)
            ->where('employee_id', $me->id)
            ->with('course')
            ->get();

        $stats = [
            'courses' => $courses->count(),
            'lessons' => (int) $courses->sum('lessons_count'),
            'enrolled' => $enrollments->count(),
            'completed' => $enrollments->filter(fn (LmsEnrollment $e) => $e->status === LmsEnrollmentStatus::Completed)->count(),
            'average' => $enrollments->isEmpty() ? 0 : (int) round($enrollments->avg('progress_percent')),
        ];

        return [
            'courses' => $courses,
            'enrollments' => $enrollments,
            'stats' => $stats,
            'activities' => $this->activities($me, 12),
            'tips' => $this->mentorTips($me, $enrollments),
        ];
    }

    /** @param array{title:string,summary?:string,description?:string,cover_color?:string,publish?:bool} $data */
    public function createCourse(array $data, User $user, Employee $me): LmsCourse
    {
        $publish = ! empty($data['publish']);

        $course = DB::transaction(function () use ($data, $me, $publish): LmsCourse {
            $course = LmsCourse::create([
                'company_id' => $me->company_id,
                'title' => trim((string) $data['title']),
                'summary' => $data['summary'] ?? null,
                'description' => $data['description'] ?? null,
                'cover_color' => ! empty($data['cover_color']) ? $data['cover_color'] : '#206bc4',
                'status' => $publish ? LmsCourseStatus::Published : LmsCourseStatus::Draft,
                'published_at' => $publish ? now() : null,
                'created_by' => $me->id,
            ]);

            LmsWhiteboard::create([
                'company_id' => $me->company_id,
                'course_id' => $course->id,
                'title' => __('Доска курса').': '.$course->title,
                'created_by' => $me->id,
            ]);

            $this->recordActivity($me->company_id, $course->id, $me->id, 'course', __('Создан курс').' «'.$course->title.'»');

            return $course;
        });

        $this->audit->log($user, 'lms.course.created', 'LmsCourse', $course->id);

        return $course;
    }

    /** @param array{title:string,summary?:string,content?:string,kind?:string,duration_min?:int,is_required?:bool} $data */
    public function createLesson(LmsCourse $course, array $data, User $user, Employee $me): LmsLesson
    {
        $this->assertCompany($me, $course->company_id);

        $lesson = DB::transaction(function () use ($course, $data, $me): LmsLesson {
            $lesson = LmsLesson::create([
                'company_id' => $me->company_id,
                'course_id' => $course->id,
                'title' => trim((string) $data['title']),
                'summary' => $data['summary'] ?? null,
                'content' => $data['content'] ?? null,
                'kind' => ! empty($data['kind']) ? $data['kind'] : 'text',
                'duration_min' => (int) ($data['duration_min'] ?? 0),
                'sort_order' => (int) ($course->lessons()->max('sort_order') ?? 0) + 1,
                'is_required' => (bool) ($data['is_required'] ?? true),
                'created_by' => $me->id,
            ]);

            LmsWhiteboard::create([
                'company_id' => $me->company_id,
                'course_id' => $course->id,
                'lesson_id' => $lesson->id,
                'title' => __('Доска урока').': '.$lesson->title,
                'created_by' => $me->id,
            ]);

            $this->recordActivity($me->company_id, $course->id, $me->id, 'lesson', __('Добавлен урок').' «'.$lesson->title.'»', ['lesson_id' => $lesson->id]);

            foreach ($course->enrollments as $enrollment) {
                LmsLessonProgress::firstOrCreate([
                    'enrollment_id' => $enrollment->id,
                    'lesson_id' => $lesson->id,
                ], [
                    'company_id' => $me->company_id,
                    'status' => LmsProgressStatus::NotStarted,
                ]);
            }

            return $lesson;
        });

        $this->audit->log($user, 'lms.lesson.created', 'LmsLesson', $lesson->id);

        return $lesson;
    }

    public function enroll(LmsCourse $course, Employee $me, User $user): LmsEnrollment
    {
        $this->assertCompany($me, $course->company_id);

        $enrollment = DB::transaction(function () use ($course, $me): LmsEnrollment {
            $enrollment = LmsEnrollment::firstOrCreate([
                'course_id' => $course->id,
                'employee_id' => $me->id,
            ], [
                'company_id' => $me->company_id,
                'status' => LmsEnrollmentStatus::Enrolled,
                'enrolled_at' => now(),
            ]);

            foreach ($course->lessons as $lesson) {
                LmsLessonProgress::firstOrCreate([
                    'enrollment_id' => $enrollment->id,
                    'lesson_id' => $lesson->id,
                ], [
                    'company_id' => $me->company_id,
                    'status' => LmsProgressStatus::NotStarted,
                ]);
            }

            $this->recordActivity($me->company_id, $course->id, $me->id, 'enroll', __('Запись на курс').' «'.$course->title.'»');

            return $enrollment;
        });

        $this->audit->log($user, 'lms.enrolled', 'LmsCourse', $course->id);

        return $enrollment;
    }

    public function completeLesson(LmsLesson $lesson, Employee $me, User $user): LmsLessonProgress
    {
        $this->assertCompany($me, $lesson->company_id);
        $course = $lesson->course;
        abort_unless($course, 404);

        $progress = DB::transaction(function () use ($lesson, $course, $me): LmsLessonProgress {
            $enrollment = LmsEnrollment::firstOrCreate([
                'course_id' => $course->id,
                'employee_id' => $me->id,
            ], [
                'company_id' => $me->company_id,
                'status' => LmsEnrollmentStatus::Enrolled,
                'enrolled_at' => now(),
            ]);

            $progress = LmsLessonProgress::updateOrCreate([
                'enrollment_id' => $enrollment->id,
                'lesson_id' => $lesson->id,
            ], [
                'company_id' => $me->company_id,
                'status' => LmsProgressStatus::Completed,
                'completed_at' => now(),
            ]);

            $this->recalculate($enrollment, $me->company_id);
            $this->recordActivity($me->company_id, $course->id, $me->id, 'lesson_done', __('Пройден урок').' «'.$lesson->title.'»', ['lesson_id' => $lesson->id]);

            return $progress;
        });

        $this->audit->log($user, 'lms.lesson.completed', 'LmsLesson', $lesson->id);

        return $progress;
    }

    private function recalculate(LmsEnrollment $enrollment, int $companyId): void
    {
        $course = $enrollment->course;
        if (! $course) {
            return;
        }

        $required = $course->lessons()->where('is_required', true)->pluck('id');
        $done = $required->isEmpty() ? 0 : LmsLessonProgress::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('status', LmsProgressStatus::Completed->value)
            ->whereIn('lesson_id', $required)
            ->count();

        $percent = $required->isEmpty() ? 100 : (int) round($done * 100 / $required->count());
        $enrollment->update([
            'company_id' => $companyId,
            'progress_percent' => min(100, $percent),
            'status' => $percent >= 100 ? LmsEnrollmentStatus::Completed : LmsEnrollmentStatus::InProgress,
            'completed_at' => $percent >= 100 ? now() : null,
        ]);
    }

    public function enrollmentFor(LmsCourse $course, Employee $me): ?LmsEnrollment
    {
        return LmsEnrollment::query()
            ->where('course_id', $course->id)
            ->where('employee_id', $me->id)
            ->first();
    }

    /** @return array<int, array<string,mixed>> */
    public function whiteboardElements(LmsWhiteboard $whiteboard, int $since, Employee $me): array
    {
        $this->assertCompany($me, $whiteboard->company_id);

        return LmsWhiteboardElement::query()
            ->where('whiteboard_id', $whiteboard->id)
            ->where('id', '>', $since)
            ->with('employee')
            ->orderBy('id')
            ->limit(400)
            ->get()
            ->map(fn (LmsWhiteboardElement $el) => [
                'id' => $el->id,
                'type' => $el->type?->value ?? $el->type,
                'payload' => $el->payload ?? [],
                'color' => $el->color,
                'width' => (int) $el->width,
                'is_erased' => (bool) $el->is_erased,
                'author' => $this->employeeName($el->employee),
            ])
            ->all();
    }

    /** @param array<string,mixed> $payload */
    public function draw(LmsWhiteboard $whiteboard, string $type, array $payload, ?string $color, int $width, Employee $me): LmsWhiteboardElement
    {
        $this->assertCompany($me, $whiteboard->company_id);
        abort_unless(LmsElementType::tryFrom($type) !== null, 422, __('Неизвестный тип элемента доски'));

        $element = LmsWhiteboardElement::create([
            'company_id' => $me->company_id,
            'whiteboard_id' => $whiteboard->id,
            'employee_id' => $me->id,
            'type' => $type,
            'payload' => $payload,
            'color' => $color,
            'width' => max(1, min(24, $width)),
            'is_erased' => false,
        ]);

        if ($type === LmsElementType::Sticky->value || $type === LmsElementType::Text->value) {
            $this->recordActivity($me->company_id, $whiteboard->course_id, $me->id, 'board', __('Заметка на доске').' «'.$whiteboard->title.'»', ['whiteboard_id' => $whiteboard->id]);
        }

        return $element;
    }

    public function erase(LmsWhiteboard $whiteboard, LmsWhiteboardElement $element, Employee $me): void
    {
        $this->assertCompany($me, $whiteboard->company_id);
        $this->assertCompany($me, $element->company_id);
        abort_unless((int) $element->whiteboard_id === (int) $whiteboard->id, 404);

        $element->update(['is_erased' => true]);
    }

    public function touchPresence(LmsWhiteboard $whiteboard, float $x, float $y, string $color, Employee $me): void
    {
        $this->assertCompany($me, $whiteboard->company_id);

        LmsWhiteboardPresence::updateOrCreate([
            'whiteboard_id' => $whiteboard->id,
            'employee_id' => $me->id,
        ], [
            'company_id' => $me->company_id,
            'x' => max(0, min(1, $x)),
            'y' => max(0, min(1, $y)),
            'color' => $color,
            'seen_at' => now(),
        ]);
    }

    /** @return array<int, array<string,mixed>> */
    public function presence(LmsWhiteboard $whiteboard, Employee $me): array
    {
        $this->assertCompany($me, $whiteboard->company_id);

        return LmsWhiteboardPresence::query()
            ->where('whiteboard_id', $whiteboard->id)
            ->where('seen_at', '>=', now()->subSeconds(25))
            ->with('employee')
            ->get()
            ->map(fn (LmsWhiteboardPresence $p) => [
                'employee_id' => (int) $p->employee_id,
                'name' => $this->employeeName($p->employee),
                'x' => (float) $p->x,
                'y' => (float) $p->y,
                'color' => $p->color,
            ])
            ->all();
    }

    /** @param array{title:string,course_id?:int,lesson_id?:int} $data */
    public function createMeeting(array $data, User $user, Employee $me): LmsMeeting
    {
        $courseId = ! empty($data['course_id']) ? (int) $data['course_id'] : null;
        $lessonId = ! empty($data['lesson_id']) ? (int) $data['lesson_id'] : null;

        if ($courseId) {
            $this->assertCompany($me, LmsCourse::query()->whereKey($courseId)->value('company_id'));
        }
        if ($lessonId) {
            $this->assertCompany($me, LmsLesson::query()->whereKey($lessonId)->value('company_id'));
        }

        $meeting = DB::transaction(function () use ($data, $courseId, $lessonId, $me): LmsMeeting {
            $meeting = LmsMeeting::create([
                'company_id' => $me->company_id,
                'course_id' => $courseId,
                'lesson_id' => $lessonId,
                'title' => trim((string) $data['title']),
                'room' => 'lms-'.Str::lower(Str::random(10)),
                'status' => LmsMeetingStatus::Scheduled,
                'host_employee_id' => $me->id,
                'starts_at' => now(),
            ]);

            LmsMeetingParticipant::create([
                'company_id' => $me->company_id,
                'meeting_id' => $meeting->id,
                'employee_id' => $me->id,
                'role' => 'host',
                'status' => 'joined',
                'joined_at' => now(),
            ]);

            $this->recordActivity($me->company_id, $courseId, $me->id, 'meeting', __('Создан видеозвонок').' «'.$meeting->title.'»', ['meeting_id' => $meeting->id]);

            return $meeting;
        });

        $this->audit->log($user, 'lms.meeting.created', 'LmsMeeting', $meeting->id);

        return $meeting;
    }

    public function joinMeeting(LmsMeeting $meeting, Employee $me): LmsMeetingParticipant
    {
        $this->assertCompany($me, $meeting->company_id);

        if ($meeting->status !== LmsMeetingStatus::Live) {
            $meeting->update(['status' => LmsMeetingStatus::Live]);
        }

        $participant = LmsMeetingParticipant::updateOrCreate([
            'meeting_id' => $meeting->id,
            'employee_id' => $me->id,
        ], [
            'company_id' => $me->company_id,
            'role' => (int) $meeting->host_employee_id === (int) $me->id ? 'host' : 'participant',
            'status' => 'joined',
            'joined_at' => now(),
            'left_at' => null,
        ]);

        $this->recordActivity($me->company_id, $meeting->course_id, $me->id, 'meeting', __('Подключение к видеозвонку').' «'.$meeting->title.'»', ['meeting_id' => $meeting->id]);

        return $participant;
    }

    public function leaveMeeting(LmsMeeting $meeting, Employee $me): void
    {
        $this->assertCompany($me, $meeting->company_id);

        LmsMeetingParticipant::where('meeting_id', $meeting->id)
            ->where('employee_id', $me->id)
            ->update(['status' => 'left', 'left_at' => now()]);
    }

    /** @return array<int, array<string,mixed>> */
    public function signalsSince(LmsMeeting $meeting, int $since, Employee $me): array
    {
        $this->assertCompany($me, $meeting->company_id);

        return LmsSignal::query()
            ->where('meeting_id', $meeting->id)
            ->where('id', '>', $since)
            ->orderBy('id')
            ->limit(200)
            ->get()
            ->map(fn (LmsSignal $s) => [
                'id' => (int) $s->id,
                'from' => (int) $s->from_employee_id,
                'to' => $s->to_employee_id === null ? null : (int) $s->to_employee_id,
                'type' => $s->type?->value ?? $s->type,
                'payload' => $s->payload ?? [],
            ])
            ->all();
    }

    /** @param array<string,mixed> $payload */
    public function postSignal(LmsMeeting $meeting, string $type, array $payload, ?int $toEmployeeId, Employee $me): LmsSignal
    {
        $this->assertCompany($me, $meeting->company_id);
        abort_unless(LmsSignalType::tryFrom($type) !== null, 422, __('Неизвестный тип сигнала'));

        if ($toEmployeeId !== null) {
            $this->assertCompany($me, Employee::query()->whereKey($toEmployeeId)->value('company_id'));
        }

        $signal = LmsSignal::create([
            'company_id' => $me->company_id,
            'meeting_id' => $meeting->id,
            'from_employee_id' => $me->id,
            'to_employee_id' => $toEmployeeId,
            'type' => $type,
            'payload' => $payload,
        ]);

        LmsSignal::query()
            ->where('meeting_id', $meeting->id)
            ->where('created_at', '<', now()->subMinutes(15))
            ->delete();

        return $signal;
    }

    /** @return array<int, array<string,mixed>> */
    public function participants(LmsMeeting $meeting, Employee $me): array
    {
        $this->assertCompany($me, $meeting->company_id);

        return LmsMeetingParticipant::query()
            ->where('meeting_id', $meeting->id)
            ->where('status', 'joined')
            ->with('employee')
            ->get()
            ->map(fn (LmsMeetingParticipant $p) => [
                'employee_id' => (int) $p->employee_id,
                'name' => $this->employeeName($p->employee),
                'role' => $p->role,
            ])
            ->all();
    }

    /** @param array{title:string,description?:string,course_id:int,lesson_id?:int,due_at?:string,max_score?:int} $data */
    public function createAssignment(array $data, User $user, Employee $me): LmsAssignment
    {
        $course = LmsCourse::query()->findOrFail((int) $data['course_id']);
        $this->assertCompany($me, $course->company_id);

        $assignment = LmsAssignment::create([
            'company_id' => $me->company_id,
            'course_id' => $course->id,
            'lesson_id' => ! empty($data['lesson_id']) ? (int) $data['lesson_id'] : null,
            'title' => trim((string) $data['title']),
            'description' => $data['description'] ?? null,
            'status' => LmsAssignmentStatus::Open,
            'due_at' => ! empty($data['due_at']) ? Carbon::parse($data['due_at']) : null,
            'max_score' => max(1, (int) ($data['max_score'] ?? 100)),
            'created_by' => $me->id,
        ]);

        $this->recordActivity($me->company_id, $course->id, $me->id, 'assignment', __('Новое практическое задание').' «'.$assignment->title.'»', ['assignment_id' => $assignment->id]);
        $this->audit->log($user, 'lms.assignment.created', 'LmsAssignment', $assignment->id);

        return $assignment;
    }

    /** @return array{board:array,assignments:Collection} */
    public function assignmentBoard(?int $courseId, Employee $me): array
    {
        $query = LmsAssignment::query()
            ->where('company_id', $me->company_id)
            ->with([
                'course',
                'lesson',
                'submissions' => fn ($q) => $q->where('company_id', $me->company_id)->with(['employee', 'reviewer'])->latest(),
            ])
            ->latest();

        if ($courseId) {
            $query->where('course_id', $courseId);
        }

        $assignments = $query->limit(120)->get();
        $board = [
            'draft' => [], 'submitted' => [], 'reviewed' => [], 'returned' => [],
        ];

        foreach ($assignments as $assignment) {
            if ($assignment->submissions->isEmpty()) {
                $board['draft'][] = [
                    'assignment' => $assignment,
                    'submission' => null,
                    'status' => 'draft',
                ];

                continue;
            }

            foreach ($assignment->submissions as $submission) {
                $key = $submission->status?->value ?? 'draft';
                $board[$key][] = [
                    'assignment' => $assignment,
                    'submission' => $submission,
                    'status' => $key,
                ];
            }
        }

        return ['board' => $board, 'assignments' => $assignments];
    }

    public function submitAssignment(LmsAssignment $assignment, string $body, ?string $link, Employee $me, User $user): LmsSubmission
    {
        $this->assertCompany($me, $assignment->company_id);

        $submission = LmsSubmission::updateOrCreate([
            'assignment_id' => $assignment->id,
            'employee_id' => $me->id,
        ], [
            'company_id' => $me->company_id,
            'body' => $body,
            'link' => $link,
            'status' => LmsSubmissionStatus::Submitted,
            'submitted_at' => now(),
            'reviewed_at' => null,
            'score' => null,
        ]);

        $this->recordActivity($me->company_id, $assignment->course_id, $me->id, 'submit', __('Задание сдано').' «'.$assignment->title.'»', ['assignment_id' => $assignment->id]);
        $this->audit->log($user, 'lms.assignment.submitted', 'LmsSubmission', $submission->id);

        return $submission;
    }

    public function reviewAssignment(LmsSubmission $submission, string $status, ?int $score, Employee $me, User $user): LmsSubmission
    {
        $this->assertCompany($me, $submission->company_id);
        abort_unless(in_array($status, ['reviewed', 'returned'], true), 422, __('Некорректный статус проверки'));

        $assignment = $submission->assignment;
        $max = $assignment?->max_score ?? 100;

        $submission->update([
            'status' => $status === 'reviewed' ? LmsSubmissionStatus::Reviewed : LmsSubmissionStatus::Returned,
            'score' => $score === null ? null : max(0, min($max, $score)),
            'reviewed_by' => $me->id,
            'reviewed_at' => now(),
        ]);

        $this->recordActivity($me->company_id, $assignment?->course_id, $me->id, 'review', __('Задание проверено').' «'.$assignment?->title.'»', ['assignment_id' => $assignment?->id]);
        $this->audit->log($user, 'lms.assignment.reviewed', 'LmsSubmission', $submission->id);

        return $submission;
    }

    /** @return array<int, array<string,mixed>> */
    public function activities(Employee $me, int $limit = 20): array
    {
        return LmsActivity::query()
            ->where('company_id', $me->company_id)
            ->with(['course', 'employee'])
            ->latest('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (LmsActivity $a) => [
                'id' => (int) $a->id,
                'type' => $a->type,
                'label' => $a->label,
                'meta' => $a->meta ?? [],
                'course' => $a->course?->title,
                'course_id' => $a->course_id,
                'author' => $this->employeeName($a->employee),
                'created_at' => $a->created_at?->diffForHumans(),
            ])
            ->all();
    }

    /** @return array<int, array<string,mixed>> */
    public function activitiesSince(Employee $me, int $since, int $limit = 30): array
    {
        return LmsActivity::query()
            ->where('company_id', $me->company_id)
            ->where('id', '>', $since)
            ->with(['course', 'employee'])
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(fn (LmsActivity $a) => [
                'id' => (int) $a->id,
                'type' => $a->type,
                'label' => $a->label,
                'course' => $a->course?->title,
                'course_id' => $a->course_id,
                'author' => $this->employeeName($a->employee),
                'created_at' => $a->created_at?->diffForHumans(),
            ])
            ->all();
    }

    /**
     * Отличает LMS от обычной системы курсов: наставник видит не только
     * процент завершения, но и «тихие» сигналы — давность активности,
     * просроченные задания и клики по доске.
     *
     * @param  SupportCollection<int,LmsEnrollment>|Collection<int,LmsEnrollment>|null  $enrollments
     * @return array<int, array<string,string>>
     */
    public function mentorTips(Employee $me, ?SupportCollection $enrollments = null): array
    {
        $enrollments ??= LmsEnrollment::query()
            ->where('company_id', $me->company_id)
            ->where('employee_id', $me->id)
            ->with('course')
            ->get();

        $tips = [];

        if ($enrollments->isEmpty()) {
            $tips[] = [
                'icon' => 'school',
                'title' => __('Первый шаг'),
                'text' => __('Выберите курс и нажмите «Записаться» — Бumi построит личный маршрут.'),
                'url' => route('lms.index'),
            ];
        }

        foreach ($enrollments as $enrollment) {
            if ($enrollment->status === LmsEnrollmentStatus::Completed || ! $enrollment->course) {
                continue;
            }

            $remaining = max(0, (int) $enrollment->course->lessons()->where('is_required', true)->count() - LmsLessonProgress::query()
                ->where('enrollment_id', $enrollment->id)
                ->where('status', LmsProgressStatus::Completed->value)
                ->count());

            $tips[] = [
                'icon' => 'brain',
                'title' => __('Продолжить маршрут'),
                'text' => __('Курс «:course»: осталось :count обязательных уроков. Бumi рекомендует не прерывать серию.', ['course' => $enrollment->course->title, 'count' => $remaining]),
                'url' => route('lms.courses.show', $enrollment->course),
            ];
        }

        $overdue = LmsSubmission::query()
            ->where('company_id', $me->company_id)
            ->where('employee_id', $me->id)
            ->where('status', LmsSubmissionStatus::Draft->value)
            ->whereHas('assignment', fn ($q) => $q->whereNotNull('due_at')->where('due_at', '<', now()))
            ->with('assignment')
            ->first();

        if ($overdue?->assignment) {
            $tips[] = [
                'icon' => 'alarm',
                'title' => __('Есть просрочка'),
                'text' => __('Задание «:task» ждёт отправки — это важный сигнал для наставника.', ['task' => $overdue->assignment->title]),
                'url' => route('lms.assignments.index'),
            ];
        }

        $silent = LmsActivity::query()
            ->where('company_id', $me->company_id)
            ->where('employee_id', $me->id)
            ->where('created_at', '<', now()->subDays(2))
            ->exists();

        if ($silent && $enrollments->isNotEmpty()) {
            $tips[] = [
                'icon' => 'pulse',
                'title' => __('Пульс затих'),
                'text' => __('Давно не было активности. Зайдите на общую доску — один штрих возвращает команду в ритм.'),
                'url' => route('lms.index'),
            ];
        }

        return array_slice($tips, 0, 4);
    }

    private function recordActivity(int $companyId, ?int $courseId, ?int $employeeId, string $type, string $label, array $meta = []): LmsActivity
    {
        return LmsActivity::create([
            'company_id' => $companyId,
            'course_id' => $courseId,
            'employee_id' => $employeeId,
            'type' => $type,
            'label' => $label,
            'meta' => $meta,
            'created_at' => now(),
        ]);
    }
}
