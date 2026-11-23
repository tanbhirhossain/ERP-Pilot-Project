<?php

use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Modules\HRM\Support\Attendance\DailyDateArgument;

test('attendance pipeline runs three times daily in Dhaka with the previous and current dates', function () {
    $events = collect(app(Schedule::class)->events());
    $pipeline = $events->first(fn ($event) => str_contains($event->command ?? '', 'hrm:pipeline'));
    $sync = $events->first(fn ($event) => str_contains($event->command ?? '', 'hrm:sync-attendance'));
    expect($pipeline)->not->toBeNull()->and($sync)->not->toBeNull()
        ->and($pipeline->expression)->toBe('0 9,14,22 * * *')
        ->and($pipeline->timezone)->toBe('Asia/Dhaka')
        ->and($pipeline->command)->toContain('--from=yesterday')->toContain('--to=today')
        ->and($pipeline->withoutOverlapping)->toBeTrue()
        ->and($sync->withoutOverlapping)->toBeTrue()
        ->and($pipeline->mutexName())->toBe($sync->mutexName());
    Carbon::setTestNow(Carbon::parse('2026-03-01 09:00:00', 'Asia/Dhaka'));
    try {
        expect(DailyDateArgument::resolve('from', 'yesterday')['value'])->toBe('2026-02-28')
            ->and(DailyDateArgument::resolve('to', 'today')['value'])->toBe('2026-03-01');
    } finally { Carbon::setTestNow(); }
});
