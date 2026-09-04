<?php

namespace Tests\Feature\Federation;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * The scheduler owns what an operator used to run by hand (ADR-0015): the
 * reconciliation, the outbox status and upstream's health checks, each with
 * a failure hook that writes the line an alarm attaches to.
 */
class ScheduleTest extends TestCase
{
    public function test_the_federation_tasks_are_scheduled_with_failure_hooks(): void
    {
        $events = collect(app(Schedule::class)->events());

        $expected = [
            'federation:reconcile-credentials' => '0 * * * *',
            'federation:outbox-status' => '*/15 * * * *',
            'health:check' => '*/15 * * * *',
        ];

        foreach ($expected as $command => $expression) {
            $event = $events->first(fn (Event $event) => str_contains($event->command ?? '', $command));

            $this->assertNotNull($event, "{$command} is scheduled");
            $this->assertSame($expression, $event->expression, "{$command} runs on the documented cadence");
            // onFailure() registers an after-callback; the property is protected.
            $afterCallbacks = (fn () => $this->afterCallbacks)->call($event);
            $this->assertNotEmpty($afterCallbacks, "{$command} has a failure hook");
        }
    }
}
