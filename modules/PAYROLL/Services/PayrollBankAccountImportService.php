<?php

namespace Modules\PAYROLL\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Models\Employee\EmployeeSalary;

/**
 * Fills each employee's bank account from the spreadsheet the hospital already keeps.
 *
 * The bank details were never missing from the organisation — they were missing from the system. The
 * staff spreadsheet has had `Payment Method`, `Bank Name` and `Bank A/C No` columns all along: 363 of
 * its 1,005 rows carry an account, all 13 digits, all DBBL, no duplicates. What the ERP had was 985
 * salary records with a blank account field, so every payroll would have gone to the cash sheet.
 *
 * This is deliberately not a general-purpose employee importer. It reads exactly three columns, and it
 * only ever writes those three fields onto an existing salary record. It cannot create an employee,
 * cannot invent a salary, and cannot change a basic pay.
 *
 * Two modes, because the difference matters when the source is somebody's working spreadsheet:
 * a preview reports exactly what would change and writes nothing, and an apply is the same code path
 * with the writes switched on — so what the officer approves is what runs.
 */
final class PayrollBankAccountImportService
{
    /** The columns this import understands, in the order a sensible sheet would carry them. */
    public const COLUMNS = [
        'employee_id' => ['Emp ID', 'Employee ID', 'Employee Id', 'EmpID', 'Staff ID'],
        'payment_method' => ['Payment Method', 'Payment', 'Method'],
        'bank_name' => ['Bank Name', 'Bank', 'BankName'],
        'bank_account_no' => ['Bank A/C No', 'Bank Account No', 'Account No', 'A/C No', 'Bank A/C', 'Account Number'],
    ];

    /**
     * Read the sheet and say what would happen — without doing any of it.
     *
     * @return array{
     *     total_rows: int, bank_rows: int, cash_rows: int, blank_rows: int,
     *     will_update: list<array<string, mixed>>, unchanged: int,
     *     problems: list<array<string, mixed>>, unmapped: list<string>,
     *     columns_found: array<string, ?string>
     * }
     */
    public function preview(string $absolutePath, string $disk = 'local'): array
    {
        $sheet = $this->read($absolutePath, $disk);

        return $this->classify($sheet);
    }

    /**
     * Do it. Same classification as the preview; only the write is new.
     *
     * @return array{updated: int, skipped: int, problems: list<array<string, mixed>>, changes: list<array<string, mixed>>}
     */
    public function apply(string $absolutePath, ?int $actorId = null, string $disk = 'local'): array
    {
        $analysis = $this->classify($this->read($absolutePath, $disk));

        $updated = 0;
        $changes = [];

        DB::transaction(function () use ($analysis, $actorId, &$updated, &$changes): void {
            foreach ($analysis['will_update'] as $row) {
                $salary = EmployeeSalary::query()
                    ->where('employee_id', $row['employee_id'])
                    ->where('is_current', true)
                    ->first();

                if ($salary === null) {
                    // An employee with no current salary record is not this import's business: writing
                    // bank details onto a salary that does not exist would mean inventing one, and the
                    // payroll would still skip them for the reason P19 already reports.
                    continue;
                }

                $salary->update([
                    'payment_method' => $row['payment_method'],
                    'bank_name' => $row['bank_name'],
                    'bank_account_no' => $row['bank_account_no'],
                ]);

                $changes[] = [
                    'employee_id' => $row['employee_id'],
                    'name' => $row['name'],
                    'payment_method' => $row['payment_method'],
                    'bank_account_no' => $row['bank_account_no'],
                    'previous' => [
                        'payment_method' => $salary->getOriginal('payment_method'),
                        'bank_account_no' => $salary->getOriginal('bank_account_no'),
                    ],
                ];
                $updated++;
            }

            Log::info('Payroll bank accounts imported', [
                'actor_id' => $actorId,
                'updated' => $updated,
                'skipped' => $analysis['unchanged'] + count($analysis['problems']),
            ]);
        });

        return [
            'updated' => $updated,
            'skipped' => $analysis['unchanged'] + count($analysis['problems']),
            'problems' => $analysis['problems'],
            'changes' => $changes,
        ];
    }

    /**
     * Rows, keyed by header name, ignoring anything this import does not ask for.
     *
     * @return array{columns_found: array<string, ?string>, rows: list<array<string, mixed>>}
     */
    private function read(string $absolutePath, string $disk): array
    {
        if (! is_file($absolutePath)) {
            throw new \DomainException('The file could not be found at '.$absolutePath.'.');
        }

        $data = Excel::toArray(new class implements \Maatwebsite\Excel\Concerns\ToArray
        {
            public function array(array $array): array
            {
                return $array;
            }
        }, $absolutePath);

        $rows = $data[0] ?? [];
        if ($rows === []) {
            throw new \DomainException('The spreadsheet has no rows.');
        }

        $header = array_map(fn ($h) => is_string($h) ? trim($h) : $h, array_shift($rows));

        $columnsFound = [];
        $indexes = [];
        foreach (self::COLUMNS as $key => $candidates) {
            $columnsFound[$key] = null;
            foreach ($candidates as $candidate) {
                $hit = array_search(mb_strtolower($candidate), array_map(fn ($h) => is_string($h) ? mb_strtolower(trim($h)) : null, $header), true);
                if ($hit !== false) {
                    $columnsFound[$key] = $header[$hit];
                    $indexes[$key] = $hit;

                    break;
                }
            }
        }

        if (! isset($indexes['employee_id'], $indexes['bank_account_no'])) {
            throw new \DomainException(sprintf(
                'This file does not look like a staff sheet: it needs an employee ID column (%s) and an account column (%s). Found columns: %s.',
                implode(' / ', self::COLUMNS['employee_id']),
                implode(' / ', self::COLUMNS['bank_account_no']),
                implode(', ', array_filter($header, fn ($h) => is_string($h) && $h !== '')),
            ));
        }

        $out = [];
        foreach ($rows as $row) {
            $get = fn (string $key) => isset($indexes[$key]) ? ($row[$indexes[$key]] ?? null) : null;

            $id = $get('employee_id');
            if ($id === null || $id === '') {
                continue;
            }

            $out[] = [
                'employee_id' => trim((string) $id),
                'payment_method' => $get('payment_method'),
                'bank_name' => $get('bank_name'),
                'bank_account_no' => $get('bank_account_no'),
            ];
        }

        return ['columns_found' => $columnsFound, 'rows' => $out];
    }

    /**
     * Work out, for each row, whether it is a bank employee, a cash employee, or a problem.
     *
     * Nothing is written here, which is the point: the preview the officer reads and the apply that
     * follows run this same function, so the numbers on the screen are the numbers that run.
     */
    private function classify(array $sheet): array
    {
        $rows = $sheet['rows'];

        $names = Employee::query()
            ->whereIn('employee_id', array_column($rows, 'employee_id'))
            ->pluck('full_name', 'employee_id');

        $salaries = EmployeeSalary::query()
            ->where('is_current', true)
            ->whereIn('employee_id', array_column($rows, 'employee_id'))
            ->get()
            ->keyBy('employee_id');

        $willUpdate = [];
        $problems = [];
        $unchanged = 0;
        $cashRows = 0;
        $bankRows = 0;
        $blankRows = 0;

        $seenAccounts = [];

        foreach ($rows as $row) {
            $id = $row['employee_id'];
            $rawAccount = $row['bank_account_no'];
            $method = is_string($row['payment_method']) ? mb_strtolower(trim($row['payment_method'])) : null;

            // Excel hands account numbers back as floats when the column is numeric, which turns a
            // 13-digit DBBL account into 1.93103E+12. Normalise before judging it.
            $account = null;
            if ($rawAccount !== null && $rawAccount !== '') {
                if (is_float($rawAccount) || is_int($rawAccount)) {
                    $account = number_format((float) $rawAccount, 0, '', '');
                } else {
                    $account = preg_replace('/[^0-9]/', '', (string) $rawAccount) ?: null;
                }
            }

            // Zero is how the spreadsheet says "no account" — 279 rows carry a literal 0 in that
            // column rather than an empty cell. Reading it as an account number meant those employees
            // were reported as having a one-digit account and dropped out of the import entirely.
            if ($account !== null && trim($account, '0') === '') {
                $account = null;
            }

            if ($account === null && $method === null) {
                $blankRows++;

                continue;
            }

            if ($account === null && $method === 'bank') {
                // The sheet says this person is paid through the bank and then gives no account for
                // them. Guessing — cash, or a neighbour's number — would be inventing a payment
                // instruction, so the row is reported and left alone.
                $problems[] = [
                    'employee_id' => $id, 'name' => $names[$id] ?? null,
                    'value' => $method,
                    'reason' => 'The sheet says this employee is paid by bank but gives no account number.',
                ];

                continue;
            }

            if ($account === null) {
                // Explicitly cash. Recorded so the split does not have to infer it later.
                $cashRows++;
                $salary = $salaries->get($id);
                if ($salary !== null && ($salary->payment_method !== 'cash' || filled($salary->bank_account_no))) {
                    $willUpdate[] = [
                        'employee_id' => $id, 'name' => $names[$id] ?? null,
                        'payment_method' => 'cash', 'bank_name' => null, 'bank_account_no' => null,
                        'change' => 'Marked as paid in cash',
                    ];
                } else {
                    $unchanged++;
                }

                continue;
            }

            $bankRows++;

            if (! preg_match('/^\d{10,16}$/', $account)) {
                $problems[] = [
                    'employee_id' => $id, 'name' => $names[$id] ?? null,
                    'value' => (string) $rawAccount,
                    'reason' => sprintf('Account number is %d digits; a bank account is 10 to 16.', strlen($account)),
                ];

                continue;
            }

            if (isset($seenAccounts[$account])) {
                $problems[] = [
                    'employee_id' => $id, 'name' => $names[$id] ?? null,
                    'value' => $account,
                    'reason' => 'The same account number is also on row '.$seenAccounts[$account].', so the bank would pay twice.',
                ];

                continue;
            }
            $seenAccounts[$account] = $id;

            if (! isset($names[$id])) {
                $problems[] = [
                    'employee_id' => $id, 'name' => null, 'value' => $account,
                    'reason' => 'No employee in the system has this ID — the row is probably somebody who has left.',
                ];

                continue;
            }

            $salary = $salaries->get($id);
            if ($salary === null) {
                $problems[] = [
                    'employee_id' => $id, 'name' => $names[$id] ?? null, 'value' => $account,
                    'reason' => 'This employee has no current salary record, so there is nothing to attach the account to.',
                ];

                continue;
            }

            if ((string) $salary->bank_account_no === $account && $salary->payment_method === 'bank') {
                $unchanged++;

                continue;
            }

            $willUpdate[] = [
                'employee_id' => $id, 'name' => $names[$id] ?? null,
                'payment_method' => 'bank',
                'bank_name' => $row['bank_name'] !== null && $row['bank_name'] !== '' ? trim((string) $row['bank_name']) : ($salary->bank_name ?? null),
                'bank_account_no' => $account,
                'change' => filled($salary->bank_account_no) ? 'Account replaced' : 'Account added',
            ];
        }

        return [
            'total_rows' => count($rows),
            'bank_rows' => $bankRows,
            'cash_rows' => $cashRows,
            'blank_rows' => $blankRows,
            'will_update' => $willUpdate,
            'unchanged' => $unchanged,
            'problems' => $problems,
            'columns_found' => $sheet['columns_found'],
        ];
    }
}
