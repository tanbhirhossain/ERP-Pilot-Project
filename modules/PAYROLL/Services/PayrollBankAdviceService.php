<?php

namespace Modules\PAYROLL\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\PAYROLL\Models\PayrollSalarySheet;
use Modules\PAYROLL\Models\PayrollSlip;
use Modules\PAYROLL\Models\PayrollBankAdvice;
use Modules\PAYROLL\Models\PayrollBankAdviceItem;
use Maatwebsite\Excel\Facades\Excel;

final class PayrollBankAdviceService
{
    public function generateForSheet(PayrollSalarySheet $sheet, ?int $bankId = null): array
    {
        return DB::transaction(function () use ($sheet, $bankId) {
            $sheet->refresh();
            if (!in_array($sheet->status, ['ready','released'], true)) throw new \DomainException('Bank advice can only be generated for ready/released sheets.');
            $slips = PayrollSlip::where('salary_sheet_id', $sheet->id)->where('net_salary','>',0)->get();
            if ($slips->isEmpty()) throw new \DomainException('No payable slips.');
            $grouped = [];
            foreach ($slips as $slip) {
                $empBankKey = $this->resolveEmployeeBank($slip);
                $key = $bankId ? (string)$bankId : ($empBankKey['bank_name'] ?? 'Default');
                $grouped[$key][] = ['slip'=>$slip, 'bankInfo'=>$empBankKey];
            }
            $advices = [];
            foreach ($grouped as $bankKey => $items) {
                $disburseBank = $bankId ? \Modules\ACCOUNTS\Models\Bank::find($bankId) : \Modules\ACCOUNTS\Models\Bank::first();
                if (!$disburseBank) throw new \DomainException('No company bank account configured (acc_banks).');
                $existing = PayrollBankAdvice::where('salary_sheet_id',$sheet->id)->where('bank_id',$disburseBank->id)->first();
                if ($existing) { $advices[] = $existing; continue; }
                $total = array_sum(array_map(fn($r)=>(float)$r['slip']->net_salary, $items));
                $adviceNo = sprintf('ADV-PAY-%04d%02d-%s-%03d', $sheet->pay_year, $sheet->pay_month, $disburseBank->code ?? 'BANK', $sheet->id);
                $advice = PayrollBankAdvice::create([
                    'advice_no' => $adviceNo, 'pay_year' => $sheet->pay_year, 'pay_month' => $sheet->pay_month,
                    'salary_sheet_id' => $sheet->id, 'bank_id' => $disburseBank->id,
                    'bank_name' => $disburseBank->bank_name, 'branch_name' => $disburseBank->branch_name, 'account_no' => $disburseBank->account_no,
                    'total_amount' => $total, 'employee_count' => count($items), 'status' => 'draft',
                    'narration' => "Salary {$sheet->pay_year}-".str_pad((string)$sheet->pay_month,2,'0',STR_PAD_LEFT)." ({$sheet->employee_count} emps)",
                    'created_by' => auth()->id(),
                ]);
                foreach ($items as $row) {
                    $slip = $row['slip']; $bi = $row['bankInfo'];
                    PayrollBankAdviceItem::create([
                        'advice_id' => $advice->id, 'employee_id' => $slip->employee_id, 'employee_name' => $slip->employee_name,
                        'department' => $slip->department, 'designation' => $slip->designation,
                        'bank_account_no' => $bi['account_no'] ?? null, 'bank_name' => $bi['bank_name'] ?? null,
                        'branch_name' => $bi['branch_name'] ?? null, 'routing_no' => $bi['routing_no'] ?? null,
                        'net_amount' => $slip->net_salary, 'reference' => sprintf('PAY%04d%02d-%s', $sheet->pay_year, $sheet->pay_month, $slip->employee_id),
                    ]);
                }
                $this->exportAdvice($advice->fresh('items'));
                $advices[] = $advice->fresh('items');
            }
            return $advices;
        });
    }

    public function exportAdvice(PayrollBankAdvice $advice): string
    {
        $advice->load('items');
        $fileName = "payroll_advice_{$advice->advice_no}.xlsx";
        $path = "payroll_advices/{$advice->pay_year}/{$advice->pay_month}/{$fileName}";
        @mkdir(dirname(storage_path("app/private/{$path}")), 0777, true);
        try {
            $rows = [];
            $rows[] = ['BEFTN Salary Advice', $advice->advice_no, "{$advice->pay_year}-{$advice->pay_month}", 'Generated: '.now()->toDateTimeString()];
            $rows[] = ["Source Bank: {$advice->bank_name} ({$advice->account_no}) - {$advice->branch_name}"];
            $rows[] = [];
            $rows[] = ['SL','Employee ID','Employee Name','Department','Bank A/C No','Bank','Branch','Routing No','Amount (BDT)','Reference'];
            $sl=1; foreach ($advice->items as $item) $rows[] = [$sl++, $item->employee_id, $item->employee_name, $item->department, $item->bank_account_no ?? 'N/A', $item->bank_name ?? '', $item->branch_name ?? '', $item->routing_no ?? '', number_format((float)$item->net_amount,2,'.',''), $item->reference];
            $rows[] = []; $rows[] = ['Total','','','','','','','', number_format((float)$advice->total_amount,2,'.',''), '', "Employees: {$advice->employee_count}"];
            if (class_exists(\Maatwebsite\Excel\Facades\Excel::class)) {
                $export = new class($rows) implements \Maatwebsite\Excel\Concerns\FromArray { public function __construct(private array $rows) {} public function array(): array { return $this->rows; } };
                Excel::store($export, $path, 'private');
            } else {
                $csvPath = str_replace('.xlsx','.csv', $path);
                $fp = fopen(storage_path("app/private/{$csvPath}"), 'w');
                foreach ($rows as $r) fputcsv($fp, $r); fclose($fp); $path=$csvPath;
            }
        } catch (\Throwable $e) { Log::warning('Payroll bank advice export failed', ['error'=>$e->getMessage()]); }
        $advice->update(['file_path'=>$path, 'file_format'=> str_ends_with($path,'.csv') ? 'csv' : 'xlsx']);
        return $path;
    }

    public function confirm(PayrollBankAdvice $advice): PayrollBankAdvice
    {
        return DB::transaction(function () use ($advice) {
            $locked = PayrollBankAdvice::lockForUpdate()->findOrFail($advice->id);
            if ($locked->status !== 'draft') throw new \DomainException('Only draft can be confirmed');
            $invalid = $locked->items()->where(function($q){ $q->whereNull('bank_account_no')->orWhere('bank_account_no','')->orWhereRaw("LENGTH(bank_account_no) < 10"); })->count();
            if ($invalid > 0) throw ValidationException::withMessages(['bank_account_no' => "$invalid employees have invalid bank A/C (10-16 digits required). Fix in HRM Employee bank fields before confirming."]);
            $locked->update(['status'=>'confirmed','confirmed_by'=>auth()->id(),'confirmed_at'=>now()]);
            return $locked->fresh();
        });
    }

    public function markPaid(PayrollBankAdvice $advice): PayrollBankAdvice
    {
        return DB::transaction(function () use ($advice) {
            $locked = PayrollBankAdvice::lockForUpdate()->findOrFail($advice->id);
            if ($locked->status !== 'confirmed') throw new \DomainException('Only confirmed can be marked paid');
            $locked->update(['status'=>'paid']);
            foreach ($locked->items as $item) {
                $slip = PayrollSlip::where('salary_sheet_id',$locked->salary_sheet_id)->where('employee_id',$item->employee_id)->first();
                if ($slip && $slip->payment_status !== 'paid') {
                    try { app(\Modules\PAYROLL\Actions\MarkPayrollSlipPaymentAction::class)->execute($slip, 'paid', auth()->id()); } catch (\Throwable $e) {}
                }
            }
            return $locked->fresh();
        });
    }

    private function resolveEmployeeBank(PayrollSlip $slip): array
    {
        $empId = $slip->employee_id;
        try {
            $emp = \Modules\HRM\Models\Employee\Employee::where('employee_id',$empId)->first();
            if ($emp && isset($emp->bank_account_no) && $emp->bank_account_no) {
                return ['account_no'=>$emp->bank_account_no,'bank_name'=>$emp->bank_name ?? '','branch_name'=>$emp->branch_name ?? '','routing_no'=>$emp->routing_no ?? ''];
            }
            $break = $slip->breakdown_json ?? [];
            if (!empty($break['employee']['bank_account_no'])) {
                return ['account_no'=>$break['employee']['bank_account_no'],'bank_name'=>$break['employee']['bank_name'] ?? '','branch_name'=>$break['employee']['branch_name'] ?? '','routing_no'=>$break['employee']['routing_no'] ?? ''];
            }
        } catch (\Throwable $e) {}
        return ['account_no'=>null,'bank_name'=>null,'branch_name'=>null,'routing_no'=>null];
    }

    public function download(PayrollBankAdvice $advice)
    {
        if (!$advice->file_path || !Storage::disk('private')->exists($advice->file_path)) { $this->exportAdvice($advice); $advice->refresh(); }
        return Storage::disk('private')->download($advice->file_path, basename($advice->file_path));
    }
}
