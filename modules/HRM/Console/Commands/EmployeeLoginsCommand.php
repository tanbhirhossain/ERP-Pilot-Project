<?php

namespace Modules\HRM\Console\Commands;

use Illuminate\Console\Command;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Services\EmployeeLoginService;

/**
 * HR login operations: backfill the employees that predate auto-provisioning,
 * reset one login, and sweep leaver access nightly.
 *
 * `--backfill` creates the logins `provision()` would have made and prints
 * each temporary password once — share them, then they are unshowable, like
 * any secret. `--reset=EMP-0042` rotates one. `--revoke-leavers` is the daily
 * sweep for last days that arrived since the resignation was approved (there
 * is no other settler: approval writes the label early and the flag only when
 * the day arrives, so this sweep is what retires tokens on the day itself).
 */
class EmployeeLoginsCommand extends Command
{
    protected $signature = 'hrm:employee-logins
        {--backfill : Create logins for employees that have none}
        {--reset= : Reset one login by employee code (prints a new temporary password)}
        {--revoke-leavers : Revoke tokens and sessions of employees whose last day has arrived}';

    protected $description = 'Backfill, reset and sweep employee logins';

    public function handle(EmployeeLoginService $logins): int
    {
        if (is_string($this->option('reset')) && $this->option('reset') !== '') {
            return $this->resetOne($logins, $this->option('reset'));
        }

        if ($this->option('backfill')) {
            return $this->backfill($logins);
        }

        if ($this->option('revoke-leavers')) {
            return $this->revokeLeavers($logins);
        }

        $missing = Employee::query()->whereNull('user_id')->count();
        $this->info(Employee::query()->count().' employees, '.$missing.' without a login.');
        $this->line('Use --backfill to create the missing logins, --reset=EMP-0001 to rotate one, --revoke-leavers for the nightly sweep.');

        return self::SUCCESS;
    }

    private function resetOne(EmployeeLoginService $logins, string $code): int
    {
        $employee = Employee::query()->where('employee_id', $code)->first();

        if ($employee === null) {
            $this->error('No employee '.$code.'.');

            return self::FAILURE;
        }

        $temp = $logins->resetLogin($employee);

        if ($temp === null) {
            $this->error('Could not reset the login for '.$code.' — see the log.');

            return self::FAILURE;
        }

        $this->info($code.': new temporary password: '.$temp);
        $this->line('All sessions and tokens were revoked; the password must be changed on next sign-in.');

        return self::SUCCESS;
    }

    private function backfill(EmployeeLoginService $logins): int
    {
        $created = 0;
        $linked = 0;
        $failed = 0;

        Employee::query()->whereNull('user_id')->orderBy('id')->chunk(200, function ($employees) use ($logins, &$created, &$linked, &$failed): void {
            foreach ($employees as $employee) {
                $result = $logins->provision($employee);

                if ($result === null) {
                    $failed++;
                    $this->warn($employee->employee_id.': FAILED — see the log.');
                } elseif ($result['created']) {
                    $created++;
                    $this->line($employee->employee_id.': created — temporary password: '.$result['tempPassword']);
                } else {
                    $linked++;
                    $this->line($employee->employee_id.': linked to the existing '.$result['user']->email.'.');
                }
            }
        });

        $this->info("Backfill done: {$created} created, {$linked} linked, {$failed} failed.");
        if ($created > 0) {
            $this->line('Share each temporary password once — they cannot be shown again.');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function revokeLeavers(EmployeeLoginService $logins): int
    {
        $revoked = 0;
        $today = now()->toDateString();

        // `resignations` eager-loaded so the leave check never queries per row.
        Employee::query()->with('resignations')->whereNotNull('user_id')->chunk(200, function ($employees) use ($logins, $today, &$revoked): void {
            foreach ($employees as $employee) {
                if (! $employee->hasLeftOnOrBefore($today)) {
                    continue;
                }

                $user = $employee->user;

                if ($user === null) {
                    continue;
                }

                $logins->revokeAccess($user);
                $revoked++;
            }
        });

        $this->info('Leaver sweep done: '.$revoked.' login(s) revoked.');

        return self::SUCCESS;
    }
}
