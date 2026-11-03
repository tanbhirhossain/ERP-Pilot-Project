<?php

namespace Modules\PAYROLL\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\PAYROLL\Models\PayrollSalarySheet;
use Modules\PAYROLL\Models\PayrollSheetReopen;
use Modules\PAYROLL\Models\PayrollJournalPost;

final class PayrollReopenService
{
    public function __construct(private readonly PayrollJournalService $journals) {}

    public function requestReopen(PayrollSalarySheet $sheet, string $reason, ?int $actorId): PayrollSheetReopen
    {
        if ($sheet->status !== 'released') throw new \DomainException('Only released sheets can be reopened.');
        if (PayrollSheetReopen::where('salary_sheet_id',$sheet->id)->whereNull('approved_at')->exists()) {
            throw new \DomainException('A reopen request is already pending for this sheet.');
        }
        return PayrollSheetReopen::create(['salary_sheet_id'=>$sheet->id,'reason'=>$reason,'requested_by'=>$actorId]);
    }

    public function approveReopen(PayrollSheetReopen $reopen, ?int $actorId): PayrollSheetReopen
    {
        return DB::transaction(function () use ($reopen, $actorId) {
            $locked = PayrollSheetReopen::lockForUpdate()->findOrFail($reopen->id);
            if ($locked->approved_at) throw new \DomainException('Already approved.');
            $sheet = PayrollSalarySheet::lockForUpdate()->findOrFail($locked->salary_sheet_id);
            if ($sheet->status !== 'released') throw new \DomainException('Sheet not in released state.');
            $accrual = PayrollJournalPost::where('salary_sheet_id',$sheet->id)->where('posting_type','salary_accrual')->where('status','posted')->first();
            $reversalId = null;
            if ($accrual) {
                $voided = $this->journals->voidPosting($accrual, "Reopen sheet {$sheet->pay_year}-{$sheet->pay_month}: {$locked->reason}");
                $reversalId = $voided->journal_id;
            }
            $sheet->update(['status'=>'ready']);
            $locked->update(['approved_by'=>$actorId,'approved_at'=>now(),'reversal_journal_id'=>$reversalId]);
            Log::info('Payroll sheet reopened', ['sheet_id'=>$sheet->id,'reopen_id'=>$locked->id,'reversal_journal'=>$reversalId]);
            return $locked->fresh();
        });
    }

    public function pending(): \Illuminate\Database\Eloquent\Collection { return PayrollSheetReopen::with(['sheet'])->whereNull('approved_at')->latest()->get(); }
}
