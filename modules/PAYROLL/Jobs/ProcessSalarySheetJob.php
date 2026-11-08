<?php

namespace Modules\PAYROLL\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\PAYROLL\Actions\ProcessMonthlyPayrollBatchAction;
use Modules\PAYROLL\Models\PayrollSalarySheet;

final class ProcessSalarySheetJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 900;

    public int $backoff = 30;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $salarySheetId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('payroll-sheet-'.$this->salarySheetId))
            ->expireAfter($this->timeout + 60)
            ->releaseAfter(60)];
    }

    public function uniqueId(): string
    {
        return 'payroll-salary-sheet-'.$this->salarySheetId;
    }

    public function handle(ProcessMonthlyPayrollBatchAction $process): void
    {
        @set_time_limit(0);
        $process->execute($this->salarySheetId);
    }

    public function failed(\Throwable $exception): void
    {
        PayrollSalarySheet::query()->whereKey($this->salarySheetId)->update([
            'status' => 'failed',
            'failure_message' => mb_substr($exception->getMessage(), 0, 60000),
            'finished_at' => now(),
        ]);
        Log::error('Payroll salary sheet processing failed.', [
            'salary_sheet_id' => $this->salarySheetId,
            'error' => $exception->getMessage(),
        ]);
    }
}
