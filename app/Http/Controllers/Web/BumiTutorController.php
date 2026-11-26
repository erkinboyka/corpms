<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domains\Ai\Services\BumiTutorService;
use App\Domains\Lms\Services\LmsService;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\LmsLesson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BumiTutorController extends Controller
{
    public function __construct(
        protected LmsService $lms,
        protected BumiTutorService $tutor,
    ) {}

    public function ask(Request $request, LmsLesson $lesson): JsonResponse
    {
        $me = $request->user()?->employee;
        abort_unless($me instanceof Employee, 403);
        $this->lms->assertTenant($me, $lesson->company_id);

        $validated = $request->validate([
            'question' => ['required', 'string', 'max:2000'],
            'mode' => ['sometimes', 'string', 'in:explain,hint,check'],
        ]);

        $reply = $this->tutor->ask(
            $request->user(),
            $me,
            $lesson,
            (string) $validated['question'],
            (string) ($validated['mode'] ?? 'explain'),
        );

        return response()->json(['ok' => true] + $reply->toArray());
    }
}
