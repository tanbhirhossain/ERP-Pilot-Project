<?php

namespace Modules\PAYROLL\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Facades\Excel;
use Modules\ACCOUNTS\Models\Bank;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Models\Employee\EmployeeSalary;
use Modules\PAYROLL\Actions\MarkPayrollSlipPaymentAction;
use Modules\PAYROLL\Models\PayrollBankAdvice;
use Modules\PAYROLL\Models\PayrollBankAdviceItem;
use Modules\PAYROLL\Models\PayrollSalarySheet;
use Modules\PAYROLL\Models\PayrollSlip;

final class PayrollBankAdviceService
{
    public function generateForSheet(PayrollSalarySheet $sheet, ?int $bankId = null): array
    {
        return DB::transaction(function () use ($sheet, $bankId) {
            $sheet->refresh();
            if (! in_array($sheet->status, ['ready', 'released'], true)) {
                throw new \DomainException('Bank advice can only be generated for ready/released sheets.');
            }
            // A slip already paid is not payable again — leaving it in would put money on the cash
            // sheet that has already left the building, and pay it twice if anyone works from the sheet.
            $slips = PayrollSlip::where('salary_sheet_id', $sheet->id)
                ->where('net_salary', '>', 0)
                ->where(fn ($q) => $q->whereNull('payment_status')->orWhere('payment_status', '!=', 'paid'))
                ->get();
            if ($slips->isEmpty()) {
                throw new \DomainException('No payable slips.');
            }
            // A hospital does not pay everyone the same way. Doctors, senior staff and anyone with a
            // bank account go into the BEFTN file; attendants, cleaners and anyone paid in hand get a
            // cash sheet at the counter. The old behaviour was to refuse the whole month when a single
            // employee had no account number, which meant one unbanked sweeper could stop 981 people
            // being paid — so the advice is now split rather than blocked.
            $grouped = [];
            $cash = [];
            foreach ($slips as $slip) {
                $empBankKey = $this->resolveEmployeeBank($slip);
                if (! preg_match('/^\d{10,16}$/', (string) ($empBankKey['account_no'] ?? ''))) {
                    $cash[] = ['slip' => $slip, 'bankInfo' => $empBankKey];

                    continue;
                }
                $key = $bankId ? (string) $bankId : ($empBankKey['bank_name'] ?? 'Default');
                $grouped[$key][] = ['slip' => $slip, 'bankInfo' => $empBankKey];
            }

            $advices = [];
            foreach ($grouped as $bankKey => $items) {
                $disburseBank = $bankId ? Bank::find($bankId) : Bank::first();
                if (! $disburseBank) {
                    throw new \DomainException('No company bank account configured (acc_banks).');
                }
                $existing = PayrollBankAdvice::where('salary_sheet_id', $sheet->id)->where('bank_id', $disburseBank->id)->first();

                // A draft can be regenerated: the split may have changed since it was built, and a
                // draft is not a bank instruction yet. A confirmed advice is frozen — regenerating it
                // would mean the bank holds a file that differs from the system.
                if ($existing && $existing->status === 'draft') {
                    $existing->items()->delete();
                    $existing->delete();
                    $existing = null;
                }

                if ($existing) {
                    $advices[] = $existing;

                    continue;
                }
                $total = array_sum(array_map(fn ($r) => (float) $r['slip']->net_salary, $items));
                $adviceNo = sprintf('ADV-PAY-%04d%02d-%s-%03d', $sheet->pay_year, $sheet->pay_month, $disburseBank->code ?? 'BANK', $sheet->id);
                $advice = PayrollBankAdvice::create([
                    'advice_no' => $adviceNo, 'pay_year' => $sheet->pay_year, 'pay_month' => $sheet->pay_month,
                    'salary_sheet_id' => $sheet->id, 'bank_id' => $disburseBank->id,
                    'bank_name' => $disburseBank->bank_name, 'branch_name' => $disburseBank->branch_name, 'account_no' => $disburseBank->account_no,
                    'total_amount' => $total, 'employee_count' => count($items), 'status' => 'draft',
                    'narration' => "Salary {$sheet->pay_year}-".str_pad((string) $sheet->pay_month, 2, '0', STR_PAD_LEFT)." ({$sheet->employee_count} emps)",
                    'created_by' => auth()->id(),
                ]);
                foreach ($items as $row) {
                    $slip = $row['slip'];
                    $bi = $row['bankInfo'];
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

            if ($cash !== []) {
                $this->exportCashSheet($sheet, $cash, $advices);
            }

            return $advices;
        });
    }

    /**
     * The people who are not paid by bank, as a sheet the cashier can sign against.
     *
     * This is not a failure list — it is how those employees are actually paid. The sheet carries a
     * signature column and a running total so it can be handed over at the counter, and the same
     * employee can never appear on both a bank advice and this sheet, because the split happened
     * before either was built.
     *
     * @param  list<array{slip: PayrollSlip, bankInfo: array<string, mixed>}>  $cash
     */
    private function exportCashSheet(PayrollSalarySheet $sheet, array $cash, array &$advices): void
    {
        $period = $sheet->pay_year.'-'.str_pad((string) $sheet->pay_month, 2, '0', STR_PAD_LEFT);
        $total = array_sum(array_map(fn ($r) => (float) $r['slip']->net_salary, $cash));

        $rows = [];
        $rows[] = ['Cash Salary Sheet', $period, 'Generated: '.now()->toDateTimeString()];
        $rows[] = ['Employees without a bank account — pay in hand and collect a signature for each.'];
        $rows[] = [];
        $rows[] = ['SL', 'Employee ID', 'Employee Name', 'Department', 'Designation', 'Amount (BDT)', 'Signature'];

        $sl = 1;
        foreach ($cash as $row) {
            $rows[] = [
                $sl++, $row['slip']->employee_id, $row['slip']->employee_name,
                $row['slip']->department, $row['slip']->designation,
                number_format((float) $row['slip']->net_salary, 2, '.', ''), '',
            ];
        }
        $rows[] = [];
        $rows[] = ['Total', '', '', '', '', number_format($total, 2, '.', ''), '', 'Employees: '.count($cash)];

        $fileName = "payroll_cash_sheet_{$period}.xlsx";
        $path = "payroll_advices/{$sheet->pay_year}/{$sheet->pay_month}/{$fileName}";

        try {
            @mkdir(dirname(storage_path("app/private/{$path}")), 0777, true);
            if (class_exists(Excel::class)) {
                $export = new class($rows) implements FromArray
                {
                    public function __construct(private array $rows) {}

                    public function array(): array
                    {
                        return $this->rows;
                    }
                };
                Excel::store($export, $path, 'private');
            } else {
                $path = str_replace('.xlsx', '.csv', $path);
                $fp = fopen(storage_path("app/private/{$path}"), 'w');
                foreach ($rows as $r) {
                    fputcsv($fp, $r);
                }
                fclose($fp);
            }

            $advices[] = ['cash_sheet' => $path, 'employee_count' => count($cash), 'total_amount' => $total];
        } catch (\Throwable $e) {
            Log::warning('Payroll cash sheet export failed', ['sheet_id' => $sheet->id, 'error' => $e->getMessage()]);
        }
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
            $rows[] = ['SL', 'Employee ID', 'Employee Name', 'Department', 'Bank A/C No', 'Bank', 'Branch', 'Routing No', 'Amount (BDT)', 'Reference'];
            $sl = 1;
            foreach ($advice->items as $item) {
                $rows[] = [$sl++, $item->employee_id, $item->employee_name, $item->department, $item->bank_account_no ?? 'N/A', $item->bank_name ?? '', $item->branch_name ?? '', $item->routing_no ?? '', number_format((float) $item->net_amount, 2, '.', ''), $item->reference];
            }
            $rows[] = [];
            $rows[] = ['Total', '', '', '', '', '', '', '', number_format((float) $advice->total_amount, 2, '.', ''), '', "Employees: {$advice->employee_count}"];
            if (class_exists(Excel::class)) {
                $export = new class($rows) implements FromArray
                {
                    public function __construct(private array $rows) {}

                    public function array(): array
                    {
                        return $this->rows;
                    }
                };
                Excel::store($export, $path, 'private');
            } else {
                $csvPath = str_replace('.xlsx', '.csv', $path);
                $fp = fopen(storage_path("app/private/{$csvPath}"), 'w');
                foreach ($rows as $r) {
                    fputcsv($fp, $r);
                } fclose($fp);
                $path = $csvPath;
            }
        } catch (\Throwable $e) {
            Log::warning('Payroll bank advice export failed', ['error' => $e->getMessage()]);
        }
        $advice->update(['file_path' => $path, 'file_format' => str_ends_with($path, '.csv') ? 'csv' : 'xlsx']);

        return $path;
    }

    /**
     * Does this advice still describe the payroll it was drawn from?
     *
     * A bank advice is a snapshot: it freezes what each employee is to be paid at the moment it is
     * generated. Nothing stopped the sheet from being recalculated afterwards, and on 2026-11-15 it
     * was — 112 slips had their income tax applied at 14:58, three and a half hours after this
     * advice was frozen at 11:30, and the sheet was released at 16:21. The advice was never
     * regenerated, so it instructed the bank to pay 88,722.98 more than the payroll recorded: exactly
     * the income tax that the recalculation had newly withheld. It pays `net + tax` for every one of
     * the 981 employees, and every difference runs upwards, which is what overpaying always looks
     * like.
     *
     * Prevention, because a stale advice is otherwise indistinguishable from a correct one — the
     * stored total agrees with its own frozen line items, and those items agree with each other.
     * Only a comparison against the live slips can tell.
     *
     * @return array{stale: bool, differences: int, advice_total: float, slips_total: float, drift: float, since: ?string, details: list<array<string, mixed>>}
     */
    public function reconciliation(PayrollBankAdvice $advice): array
    {
        $slipNets = PayrollSlip::query()
            ->where('salary_sheet_id', $advice->salary_sheet_id)
            ->where('net_salary', '>', 0)
            ->pluck('net_salary', 'employee_id');

        $differences = [];
        $adviceTotal = 0.0;

        foreach ($advice->items()->get(['employee_id', 'net_amount']) as $item) {
            $adviceTotal += (float) $item->net_amount;
            $slipNet = $slipNets[$item->employee_id] ?? null;

            if ($slipNet === null || abs((float) $slipNet - (float) $item->net_amount) > 0.009) {
                $differences[] = [
                    'employee_id' => $item->employee_id,
                    'on_advice' => round((float) $item->net_amount, 2),
                    'on_slip' => $slipNet === null ? null : round((float) $slipNet, 2),
                ];
            }
        }

        $slipsTotal = round((float) $slipNets->sum(), 2);

        // When the payroll moved after the advice was frozen — the useful half of the diagnosis.
        $since = PayrollSlip::query()
            ->where('salary_sheet_id', $advice->salary_sheet_id)
            ->where('updated_at', '>', $advice->created_at)
            ->max('updated_at');

        return [
            'stale' => $differences !== [] || $slipNets->count() !== $advice->items()->count(),
            'differences' => count($differences),
            'advice_total' => round($adviceTotal, 2),
            'slips_total' => $slipsTotal,
            'drift' => round($adviceTotal - $slipsTotal, 2),
            'since' => $since,
            'details' => array_slice($differences, 0, 25),
        ];
    }

    /**
     * Refuse to let a stale advice reach the bank.
     *
     * @throws ValidationException
     */
    public function assertCurrent(PayrollBankAdvice $advice): void
    {
        $check = $this->reconciliation($advice);

        if (! $check['stale']) {
            return;
        }

        throw ValidationException::withMessages([
            'advice' => sprintf(
                'This advice was generated on %s and no longer matches the payroll: %d of %d employees differ, '
                .'and the advice total is %s against %s on the slips (%s %s). Payroll changed%s. '
                .'Regenerate the advice before confirming it — an advice that pays a different amount than the slip is an over- or under-payment waiting to happen.',
                $advice->created_at?->format('d M Y H:i') ?? 'an unknown date',
                $check['differences'],
                $advice->items()->count(),
                number_format($check['advice_total'], 2),
                number_format($check['slips_total'], 2),
                $check['drift'] >= 0 ? 'overpaid' : 'underpaid',
                number_format(abs($check['drift']), 2),
                $check['since'] ? ' at '.$check['since'] : '',
            ),
        ]);
    }

    public function confirm(PayrollBankAdvice $advice): PayrollBankAdvice
    {
        return DB::transaction(function () use ($advice) {
            $locked = PayrollBankAdvice::lockForUpdate()->findOrFail($advice->id);
            if ($locked->status !== 'draft') {
                throw new \DomainException('Only draft can be confirmed');
            }
            // A frozen advice that no longer matches the payroll must not become spendable.
            $this->assertCurrent($locked);
            // An advice is a bank instruction, so everyone on it needs an account the bank will accept.
            // Nobody without one can reach this point any more — the split happens at generation — but
            // a stored draft from before the split could still carry a blank account, and confirming it
            // would instruct the bank to pay a null beneficiary.
            $invalid = $locked->items()->where(function ($q) {
                $q->whereNull('bank_account_no')->orWhere('bank_account_no', '')->orWhereRaw('LENGTH(bank_account_no) < 10');
            })->count();
            if ($invalid > 0) {
                throw ValidationException::withMessages(['bank_account_no' => "$invalid of {$locked->employee_count} employees on this advice have no valid bank A/C (10-16 digits required). Regenerate the advice so they are moved to the cash sheet, or add their account in HRM → Employee → Salary."]);
            }
            $locked->update(['status' => 'confirmed', 'confirmed_by' => auth()->id(), 'confirmed_at' => now()]);

            return $locked->fresh();
        });
    }

    public function markPaid(PayrollBankAdvice $advice): PayrollBankAdvice
    {
        return DB::transaction(function () use ($advice) {
            $locked = PayrollBankAdvice::lockForUpdate()->findOrFail($advice->id);
            if ($locked->status !== 'confirmed') {
                throw new \DomainException('Only confirmed can be marked paid');
            }
            // Confirmation is not a licence to pay a number the payroll has since contradicted.
            $this->assertCurrent($locked);
            $locked->update(['status' => 'paid']);
            foreach ($locked->items as $item) {
                $slip = PayrollSlip::where('salary_sheet_id', $locked->salary_sheet_id)->where('employee_id', $item->employee_id)->first();
                if ($slip && $slip->payment_status !== 'paid') {
                    try {
                        app(MarkPayrollSlipPaymentAction::class)->execute($slip, 'paid', auth()->id());
                    } catch (\Throwable $e) {
                        // Was swallowed: the advice had already been set to paid a few lines above,
                        // so the bank file said paid while this employee's slip still said unpaid —
                        // and inside DB::transaction, swallowing is what prevented the rollback.
                        // PayrollFinanceController::markAdvicePaid() catches \Throwable and flashes it.
                        throw new \DomainException("Marked the advice paid but could not update the slip for {$item->employee_name}: {$e->getMessage()}", 0, $e);
                    }
                }
            }

            return $locked->fresh();
        });
    }

    /**
     * Where an employee's bank details actually live.
     *
     * This used to read `employees.bank_account_no`, a column that has never existed on that table —
     * the fields are on `employee_salaries`, which is where the HRM employee form writes them. The
     * read never threw, because `isset()` on a missing attribute is simply false, so every advice was
     * generated with a null account number and no explanation anywhere.
     *
     * Order: the salary record (the source of truth the form writes), then the employee row (in case
     * this is ever moved there), then whatever the slip captured at calculation time.
     */
    private function resolveEmployeeBank(PayrollSlip $slip): array
    {
        $empId = $slip->employee_id;
        try {
            $salary = EmployeeSalary::query()
                ->where('employee_id', $empId)
                ->where('is_current', true)
                ->orderByDesc('effective_from')
                ->first()
                ?? EmployeeSalary::query()->where('employee_id', $empId)->orderByDesc('effective_from')->first();

            if ($salary && filled($salary->bank_account_no)) {
                return [
                    'account_no' => $salary->bank_account_no,
                    'bank_name' => $salary->bank_name ?? '',
                    'branch_name' => '',
                    'routing_no' => '',
                ];
            }

            $emp = Employee::where('employee_id', $empId)->first();
            if ($emp && isset($emp->bank_account_no) && $emp->bank_account_no) {
                return ['account_no' => $emp->bank_account_no, 'bank_name' => $emp->bank_name ?? '', 'branch_name' => $emp->branch_name ?? '', 'routing_no' => $emp->routing_no ?? ''];
            }

            $break = $slip->breakdown_json ?? [];
            if (! empty($break['employee']['bank_account_no'])) {
                return ['account_no' => $break['employee']['bank_account_no'], 'bank_name' => $break['employee']['bank_name'] ?? '', 'branch_name' => $break['employee']['branch_name'] ?? '', 'routing_no' => $break['employee']['routing_no'] ?? ''];
            }
        } catch (\Throwable $e) {
            // Deliberately logged rather than rethrown: this is a best-effort read made while
            // generating an advice, and aborting the whole bank file because one employee's details
            // could not be resolved would be worse than proceeding. But it must not be silent —
            // a null account number reaching the bank file has to be findable afterwards.
            Log::warning('Payroll bank details unresolved, falling back to null', [
                'slip_id' => $slip->id,
                'employee_id' => $empId,
                'error' => $e->getMessage(),
            ]);
        }

        return ['account_no' => null, 'bank_name' => null, 'branch_name' => null, 'routing_no' => null];
    }

    public function download(PayrollBankAdvice $advice)
    {
        if (! $advice->file_path || ! Storage::disk('private')->exists($advice->file_path)) {
            $this->exportAdvice($advice);
            $advice->refresh();
        }

        return Storage::disk('private')->download($advice->file_path, basename($advice->file_path));
    }
}
