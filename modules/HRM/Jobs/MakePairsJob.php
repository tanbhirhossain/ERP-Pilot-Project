<?php

namespace Modules\HRM\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\HRM\Services\Attendance\AttendancePipeline;

class MakePairsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public int $limit = 5000, public ?string $from = null, public ?string $to = null) {}

    public function handle(AttendancePipeline $pipeline): void
    {
        @set_time_limit(0);
        Log::info('[Job] Stage 2 MakePairsJob started', ['limit' => $this->limit, 'from' => $this->from, 'to' => $this->to]);
        $result = $pipeline->stage2MakePairs($this->limit, $this->from, $this->to);
        Log::info('[Job] Stage 2 finished', $result);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('MakePairsJob failed', ['error' => $e->getMessage()]);
    }
}
