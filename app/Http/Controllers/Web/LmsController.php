<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Lms\Services\LmsService;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\LmsAssignment;
use App\Models\LmsCourse;
use App\Models\LmsLesson;
use App\Models\LmsMeeting;
use App\Models\LmsMessage;
use App\Models\LmsSubmission;
use App\Models\LmsWhiteboard;
use App\Models\LmsWhiteboardElement;
use App\Support\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class LmsController extends Controller
{
    private const CURSOR_COLORS = ['#206bc4', '#00b8d9', '#ae3ec9', '#f76707', '#2fb344', '#e0493a', '#7048e8'];

    public function __construct(private readonly LmsService $lms) {}

    private function me(Request $request): Employee
    {
        $employee = $request->user()?->employee;
        abort_unless($employee instanceof Employee, 403);

        return $employee;
    }

    private function can(Request $request, string $permission): bool
    {
        return app(PermissionService::class)->check($request->user(), $permission);
    }

    private function cursorColor(Employee $me): string
    {
        return self::CURSOR_COLORS[$me->id % count(self::CURSOR_COLORS)];
    }

    public function index(Request $request): View
    {
        $me = $this->me($request);

        return view('lms.index', array_merge($this->lms->dashboard($me), [
            'canManage' => $this->can($request, 'lms.manage'),
            'canTeach' => $this->can($request, 'lms.teach'),
        ]));
    }

    public function course(Request $request, LmsCourse $course): View
    {
        $me = $this->me($request);
        $this->lms->assertTenant($me, $course->company_id);

        $enrollment = $this->lms->enrollmentFor($course, $me);
        $progress = [];

        if ($enrollment) {
            foreach ($enrollment->lessonProgress as $item) {
                $progress[$item->lesson_id] = $item->status?->value ?? $item->status;
            }
        }

        $tips = $this->lms->mentorTips($me, $enrollment ? collect([$enrollment]) : collect());

        return view('lms.course', [
            'course' => $course->load(['lessons', 'creator']),
            'enrollment' => $enrollment,
            'progress' => $progress,
            'board' => $course->whiteboards()->whereNull('lesson_id')->first(),
            'meetings' => $course->meetings()->with('host')->limit(8)->get(),
            'activities' => $this->lms->activities($me, 8),
            'tips' => $tips,
            'canManage' => $this->can($request, 'lms.manage'),
            'canTeach' => $this->can($request, 'lms.teach'),
        ]);
    }

    public function lesson(Request $request, LmsLesson $lesson): View
    {
        $me = $this->me($request);
        $this->lms->assertTenant($me, $lesson->company_id);

        $course = $lesson->course;
        abort_unless($course, 404);
        $enrollment = $this->lms->enrollmentFor($course, $me);

        $done = false;
        if ($enrollment) {
            $done = $enrollment->lessonProgress()
                ->where('lesson_id', $lesson->id)
                ->where('status', 'completed')
                ->exists();
        }

        return view('lms.lesson', [
            'lesson' => $lesson,
            'course' => $course,
            'enrollment' => $enrollment,
            'done' => $done,
            'board' => $lesson->whiteboards()->first(),
            'meeting' => $lesson->meetings()->where('status', '!=', 'ended')->latest()->first(),
            'assignments' => $lesson->assignments()->with('submissions')->get(),
            'tips' => $this->lms->mentorTips($me, $enrollment ? collect([$enrollment]) : collect()),
            'canManage' => $this->can($request, 'lms.manage'),
        ]);
    }

    public function whiteboard(Request $request, LmsWhiteboard $whiteboard): View
    {
        $me = $this->me($request);
        $this->lms->assertTenant($me, $whiteboard->company_id);

        $course = $whiteboard->course ?: $whiteboard->lesson?->course;
        $meeting = $whiteboard->lesson
            ? $whiteboard->lesson->meetings()->where('status', '!=', 'ended')->latest()->first()
            : null;
        $meeting ??= $course?->meetings()->where('status', '!=', 'ended')->latest()->first();

        // Общая комната нужна всегда: доска без созвона бесполезна.
        if ($meeting === null && $course !== null) {
            $meeting = $this->lms->createMeeting([
                'title' => __('Общая комната').': '.$course->title,
                'course_id' => $course->id,
            ], $request->user(), $me);
        }

        $servers = (array) config('lms.ice_servers', []);
        $turn = (array) config('lms.turn', []);
        if (! empty($turn['urls'])) {
            $servers[] = array_filter($turn, static fn ($value) => $value !== null && $value !== '');
        }

        return view('lms.whiteboard', [
            'whiteboard' => $whiteboard->load(['course', 'lesson']),
            'me' => $me,
            'myId' => (int) $me->id,
            'myName' => trim($me->last_name.' '.$me->first_name) ?: $me->employee_no,
            'myColor' => $this->cursorColor($me),
            'pollMs' => (int) config('lms.poll_ms', 1600),
            'chatCourse' => $course,
            'meeting' => $meeting,
            'meetingParticipants' => $meeting ? $this->lms->participants($meeting, $me) : [],
            'iceServers' => $servers,
            'assignmentBoard' => $this->lms->assignmentBoard($course?->id, $me),
            'canManage' => $this->can($request, 'lms.manage'),
        ]);
    }

    public function meeting(Request $request, LmsMeeting $meeting): View
    {
        $me = $this->me($request);
        $this->lms->assertTenant($me, $meeting->company_id);

        $servers = (array) config('lms.ice_servers', []);
        $turn = (array) config('lms.turn', []);

        if (! empty($turn['urls'])) {
            $servers[] = array_filter($turn, static fn ($value) => $value !== null && $value !== '');
        }

        return view('lms.meeting', [
            'meeting' => $meeting->load(['course', 'lesson', 'host']),
            'me' => $me,
            'myId' => (int) $me->id,
            'myName' => trim($me->last_name.' '.$me->first_name) ?: $me->employee_no,
            'participants' => $this->lms->participants($meeting, $me),
            'iceServers' => $servers,
            'pollMs' => (int) config('lms.poll_ms', 1600),
        ]);
    }

    public function assignments(Request $request): View
    {
        $me = $this->me($request);
        $courses = LmsCourse::query()
            ->where('company_id', $me->company_id)
            ->orderBy('title')
            ->get(['id', 'title']);

        $courseId = (int) $request->query('course_id', 0);
        if ($courseId > 0 && ! $courses->contains('id', $courseId)) {
            abort(403);
        }

        return view('lms.assignments', [
            ...$this->lms->assignmentBoard($courseId > 0 ? $courseId : null, $me),
            'courses' => $courses,
            'courseId' => $courseId,
            'canManage' => $this->can($request, 'lms.manage'),
        ]);
    }

    public function pulse(Request $request): View
    {
        $me = $this->me($request);
        $dashboard = $this->lms->dashboard($me);

        return view('lms.pulse', [
            'activities' => $dashboard['activities'],
            'tips' => $dashboard['tips'],
            'stats' => $dashboard['stats'],
            'pollMs' => (int) config('lms.poll_ms', 1600),
        ]);
    }

    public function storeCourse(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'summary' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string'],
            'cover_color' => ['nullable', 'string', 'max:16'],
            'publish' => ['sometimes', 'boolean'],
            'photo' => app(\App\Support\EntityPhotoService::class)->rules(),
            'reset_photo' => ['nullable', 'boolean'],
        ]);

        $course = $this->lms->createCourse($data, $request->user(), $this->me($request));
        app(\App\Support\EntityPhotoService::class)->sync($course, $request, 'lms-courses');

        return redirect()->route('lms.courses.show', $course)->with('status', __('Курс создан'));
    }

    public function storeLesson(Request $request, LmsCourse $course): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'summary' => ['nullable', 'string', 'max:500'],
            'content' => ['nullable', 'string'],
            'kind' => ['nullable', 'string', 'in:text,whiteboard,meeting'],
            'duration_min' => ['nullable', 'integer', 'min:0', 'max:600'],
            'is_required' => ['sometimes', 'boolean'],
        ]);

        $lesson = $this->lms->createLesson($course, $data, $request->user(), $this->me($request));

        return redirect()->route('lms.lessons.show', $lesson)->with('status', __('Урок добавлен'));
    }

    public function enroll(Request $request, LmsCourse $course): RedirectResponse
    {
        $this->lms->enroll($course, $this->me($request), $request->user());

        return back()->with('status', __('Вы записаны на курс'));
    }

    public function completeLesson(Request $request, LmsLesson $lesson): RedirectResponse
    {
        $this->lms->completeLesson($lesson, $this->me($request), $request->user());

        return back()->with('status', __('Урок отмечен как пройденный'));
    }

    public function whiteboardElements(Request $request, LmsWhiteboard $whiteboard): JsonResponse
    {
        $since = (int) $request->query('since', 0);

        return response()->json([
            'ok' => true,
            'elements' => $this->lms->whiteboardElements($whiteboard, $since, $this->me($request)),
        ]);
    }

    public function draw(Request $request, LmsWhiteboard $whiteboard): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'in:path,rect,ellipse,text,sticky'],
            'payload' => ['required', 'array'],
            'color' => ['nullable', 'string', 'max:24'],
            'width' => ['nullable', 'integer', 'min:1', 'max:24'],
        ]);

        $element = $this->lms->draw(
            $whiteboard,
            $data['type'],
            $data['payload'],
            $data['color'] ?? null,
            (int) ($data['width'] ?? 3),
            $this->me($request),
        );

        return response()->json(['ok' => true, 'id' => $element->id], 201);
    }

    public function erase(Request $request, LmsWhiteboard $whiteboard, LmsWhiteboardElement $element): JsonResponse
    {
        $this->lms->erase($whiteboard, $element, $this->me($request));

        return response()->json(['ok' => true, 'id' => $element->id]);
    }

    public function presence(Request $request, LmsWhiteboard $whiteboard): JsonResponse
    {
        $data = $request->validate([
            'x' => ['required', 'numeric', 'min:0', 'max:1'],
            'y' => ['required', 'numeric', 'min:0', 'max:1'],
        ]);

        $this->lms->touchPresence($whiteboard, (float) $data['x'], (float) $data['y'], $this->cursorColor($this->me($request)), $this->me($request));

        return response()->json(['ok' => true]);
    }

    public function presenceList(Request $request, LmsWhiteboard $whiteboard): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'presence' => $this->lms->presence($whiteboard, $this->me($request)),
        ]);
    }

    public function createMeeting(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'course_id' => ['nullable', 'integer', 'exists:lms_courses,id'],
            'lesson_id' => ['nullable', 'integer', 'exists:lms_lessons,id'],
        ]);

        $meeting = $this->lms->createMeeting($data, $request->user(), $this->me($request));

        return redirect()->route('lms.meetings.show', $meeting)->with('status', __('Комната создана'));
    }

    public function joinMeeting(Request $request, LmsMeeting $meeting): JsonResponse
    {
        $participant = $this->lms->joinMeeting($meeting, $this->me($request));

        return response()->json(['ok' => true, 'role' => $participant->role]);
    }

    public function leaveMeeting(Request $request, LmsMeeting $meeting): JsonResponse
    {
        $this->lms->leaveMeeting($meeting, $this->me($request));

        return response()->json(['ok' => true]);
    }

    public function signals(Request $request, LmsMeeting $meeting): JsonResponse
    {
        $since = (int) $request->query('since', 0);

        return response()->json([
            'ok' => true,
            'signals' => $this->lms->signalsSince($meeting, $since, $this->me($request)),
        ]);
    }

    public function signal(Request $request, LmsMeeting $meeting): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'in:hello,offer,answer,ice,bye,control'],
            'to_employee_id' => ['nullable', 'integer'],
            'payload' => ['required', 'array'],
        ]);

        $signal = $this->lms->postSignal(
            $meeting,
            $data['type'],
            $data['payload'],
            $data['to_employee_id'] === null ? null : (int) $data['to_employee_id'],
            $this->me($request),
        );

        return response()->json(['ok' => true, 'id' => $signal->id], 201);
    }

    public function participants(Request $request, LmsMeeting $meeting): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'participants' => $this->lms->participants($meeting, $this->me($request)),
        ]);
    }

    public function storeAssignment(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string'],
            'course_id' => ['required', 'integer', 'exists:lms_courses,id'],
            'lesson_id' => ['nullable', 'integer', 'exists:lms_lessons,id'],
            'due_at' => ['nullable', 'date'],
            'max_score' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'photo' => app(\App\Support\EntityPhotoService::class)->rules(),
            'reset_photo' => ['nullable', 'boolean'],
        ]);

        $assignment = $this->lms->createAssignment($data, $request->user(), $this->me($request));
        app(\App\Support\EntityPhotoService::class)->sync($assignment, $request, 'lms-assignments');

        return redirect()->route('lms.assignments.index')->with('status', __('Задание создано'));
    }

    public function submitAssignment(Request $request, LmsAssignment $assignment): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:10000'],
            'link' => ['nullable', 'url', 'max:500'],
        ]);

        $submission = $this->lms->submitAssignment($assignment, (string) ($data['body'] ?? ''), $data['link'] ?? null, $this->me($request), $request->user());

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'id' => $submission->id,
                'status' => $submission->status?->value ?? $submission->status,
                'message' => __('Работа отправлена на проверку'),
            ]);
        }

        return back()->with('status', __('Работа отправлена на проверку'));
    }

    public function reviewAssignment(Request $request, LmsSubmission $submission): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', 'in:reviewed,returned'],
            'score' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ]);

        $this->lms->reviewAssignment($submission, $data['status'], $data['score'] === null ? null : (int) $data['score'], $this->me($request), $request->user());

        return back()->with('status', __('Проверка сохранена'));
    }

    public function courseMessages(Request $request, LmsCourse $course): JsonResponse
    {
        $me = $this->me($request);
        $this->lms->assertTenant($me, $course->company_id);

        $since = (int) $request->query('since', 0);
        $messages = LmsMessage::query()
            ->where('company_id', $me->company_id)
            ->where('course_id', $course->id)
            ->when($since > 0, static fn ($query) => $query->where('id', '>', $since))
            ->with('employee')
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->map(static fn (LmsMessage $message): array => [
                'id' => $message->id,
                'body' => $message->body,
                'employee_id' => $message->employee_id,
                'author' => $message->employee
                    ? (trim($message->employee->last_name.' '.$message->employee->first_name)
                        ?: $message->employee->employee_no)
                    : __('Система'),
                'created_at' => $message->created_at?->format('d.m.Y H:i'),
            ]);

        return response()->json([
            'ok' => true,
            'last_id' => (int) $messages->max('id'),
            'messages' => $messages->values(),
        ]);
    }

    public function storeCourseMessage(Request $request, LmsCourse $course): JsonResponse
    {
        $me = $this->me($request);
        $this->lms->assertTenant($me, $course->company_id);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $message = LmsMessage::create([
            'company_id' => $me->company_id,
            'course_id' => $course->id,
            'employee_id' => $me->id,
            'body' => trim($data['body']),
        ]);

        app(AuditService::class)->log(
            $request->user(),
            'lms.course_message.created',
            'LmsMessage',
            $message->id,
            null,
            ['course_id' => $course->id, 'body' => $message->body],
        );

        return response()->json([
            'ok' => true,
            'id' => $message->id,
        ], 201);
    }

    public function pulseEvents(Request $request): JsonResponse
    {
        $since = (int) $request->query('since', 0);

        return response()->json([
            'ok' => true,
            'events' => $this->lms->activitiesSince($this->me($request), $since),
        ]);
    }
}
