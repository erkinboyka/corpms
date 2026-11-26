<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use ReflectionProperty;
use Tests\TestCase;

/** TEST-08: расписание зафиксировано — случайное изменение ловится тестом. */
final class ScheduleTest extends TestCase
{
    /** @return array<string, Event> */
    private function events(): array
    {
        /** @var Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);
        $out = [];
        foreach ($schedule->events() as $event) {
            if ($event->description !== '') {
                $out[$event->description] = $event;
            }
        }

        return $out;
    }

    public function test_schedule_contains_exactly_ten_named_jobs(): void
    {
        $events = $this->events();

        self::assertSame([
            'attendance.detect-missing-checkout',
            'tasks.mark-overdue',
            'db.backup',
            'files.backup',
            'ops.health',
            'biometrics.prune-raw-payloads',
            'lms.prune',
            'ops.worker-selfcheck',
            'ops.queue-watchdog',
            'ops.queue-health',
        ], array_keys($events));
    }

    public function test_schedule_expressions_are_unchanged(): void
    {
        $events = $this->events();

        self::assertSame([
            'attendance.detect-missing-checkout' => '30 8 * * *',
            'tasks.mark-overdue' => '0 * * * *',
            'db.backup' => '30 3 * * *',
            'files.backup' => '40 3 * * *',
            'ops.health' => '*/15 * * * *',
            'biometrics.prune-raw-payloads' => '10 4 * * *',
            'lms.prune' => '20 4 * * *',
            'ops.worker-selfcheck' => '*/5 * * * *',
            'ops.queue-watchdog' => '*/10 * * * *',
            'ops.queue-health' => '*/15 * * * *',
        ], array_map(static fn (Event $event): string => $event->getExpression(), $events));
    }

    public function test_long_running_jobs_cannot_overlap(): void
    {
        $events = $this->events();
        $property = new ReflectionProperty(Event::class, 'withoutOverlapping');
        $property->setAccessible(true);

        foreach ([
            'db.backup',
            'files.backup',
            'ops.health',
            'biometrics.prune-raw-payloads',
            'ops.worker-selfcheck',
            'ops.queue-watchdog',
            'ops.queue-health',
        ] as $name) {
            self::assertTrue((bool) $property->getValue($events[$name]), $name.' должен быть без пересечений');
        }
    }
}
