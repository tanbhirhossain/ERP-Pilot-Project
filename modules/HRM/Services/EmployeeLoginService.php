<?php

namespace Modules\HRM\Services;

use App\Models\User as AppUser;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\CORE\Models\User;
use Modules\HRM\Models\Employee\Employee;

/**
 * Employee login provisioning: one employee, at most one user, no admin trip
 * to the users screen.
 *
 * Creating an employee provisions the login (random temporary password, forced
 * change on first sign-in); HR resets it with `resetLogin()` or the
 * `hrm:employee-logins` command. Leavers are locked out by
 * {@see Employee::hasLeftOnOrBefore()} — the same date payroll asks, so notice
 * periods keep working and legacy label-only rows stay locked — and every
 * authentication path plus the login-policy middleware funnels through
 * {@see isLeaverLocked()}.
 *
 * Provisioning never fails the hire: a lost race links the winner, anything
 * else is logged and the backfill command recovers it.
 */
class EmployeeLoginService
{
    /** One request asks about the same user from several guards; ask once. */
    private array $leaverMemo = [];

    /**
     * @return array{user: User, tempPassword: ?string, created: bool}|null
     */
    public function provision(Employee $employee): ?array
    {
        $employee->refresh();

        if ($employee->user_id !== null) {
            $linked = User::query()->find($employee->user_id);

            if ($linked !== null) {
                return ['user' => $linked, 'tempPassword' => null, 'created' => false];
            }
            // Dangling link (user removed by hand): fall through and make a fresh one.
        }

        $email = Str::lower(trim((string) ($employee->email ?? '')));
        if ($email === '') {
            $email = $this->placeholderEmail($employee);
        }

        try {
            $existing = User::query()->where('email', $email)->first();

            // Rehire, or an address HR already uses: link, never duplicate, and
            // never touch somebody else's password or force flag.
            if ($existing !== null) {
                $employee->update(['user_id' => $existing->id]);

                return ['user' => $existing, 'tempPassword' => null, 'created' => false];
            }

            $temp = $this->tempPassword();
            $user = new User([
                'name' => $employee->full_name ?: trim($employee->first_name.' '.$employee->last_name) ?: 'Employee '.$employee->employee_id,
                'email' => $email,
                'password' => Hash::make($temp),
            ]);
            // Neither column is fillable on purpose — no request may set them —
            // so a server-side provisioning is a forceFill, like the SSO one.
            $user->forceFill([
                'force_password_change' => true,
                'provisioned_employee_code' => $employee->employee_id,
            ])->save();

            $employee->update(['user_id' => $user->id]);

            return ['user' => $user, 'tempPassword' => $temp, 'created' => true];
        } catch (QueryException $e) {
            // Lost a race on the unique address: link the winner. Anything
            // else surfaces as a logged miss the backfill command recovers.
            $winner = User::query()->where('email', $email)->first();

            if ($winner !== null) {
                $employee->update(['user_id' => $winner->id]);

                return ['user' => $winner, 'tempPassword' => null, 'created' => false];
            }

            Log::warning('Employee login provisioning failed.', [
                'employee' => $employee->employee_id,
                'error' => $e->getMessage(),
            ]);

            return null;
        } catch (\Throwable $e) {
            Log::warning('Employee login provisioning failed.', [
                'employee' => $employee->employee_id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * HR "reset login": new temporary password, forced change, and every
     * existing session and token killed so the old password dies everywhere
     * at once. Provisions first when the employee never had a login.
     */
    public function resetLogin(Employee $employee): ?string
    {
        $user = $employee->user;

        if ($user === null) {
            $provisioned = $this->provision($employee);

            return $provisioned['tempPassword'] ?? null;
        }

        $temp = $this->tempPassword();
        $user->forceFill([
            'password' => Hash::make($temp),
            'force_password_change' => true,
        ])->save();

        $this->revokeAccess($user);

        return $temp;
    }

    /**
     * Kill everything a user can act with: Sanctum tokens, web sessions, and
     * push-device rows (a leaver keeps no bell). Called on the leaving moment
     * itself, by the nightly sweep, and defensively by the middleware.
     */
    public function revokeAccess(AppUser $user): void
    {
        // The guards hand over whatever the auth provider resolved — usually
        // the CORE user, but the base app user in tests and unswapped
        // contexts, which has no tokens relation. The id-scoped fallback
        // covers it: both classes read the same users row.
        if (method_exists($user, 'tokens')) {
            $user->tokens()->delete();
        } else {
            DB::table('personal_access_tokens')->where('tokenable_id', $user->getKey())->delete();
        }
        DB::table('sessions')->where('user_id', $user->getKey())->delete();
        DB::table('user_device_tokens')->where('user_id', $user->getKey())->delete();
    }

    /**
     * Delete the login with the employee — but only a login this feature
     * created for *this* employee. A hand-linked account survives; its tokens
     * were still revoked above, because the employment ended either way.
     *
     * @return bool|null null when there was no linked user at all.
     */
    public function deleteProvisionedUser(Employee $employee): ?bool
    {
        $user = $employee->user;

        if ($user === null) {
            return null;
        }

        $this->revokeAccess($user);

        if (($user->provisioned_employee_code ?? null) !== $employee->employee_id) {
            return false;
        }

        // Sanctum tokens are gone above; device rows, SSO identities and role
        // pivots cascade; notifications are a morph with no cascade, so they go
        // explicitly. The activity log keeps its causer rows — audit history
        // outlives the account.
        $user->notifications()->delete();
        $user->delete();

        return true;
    }

    /**
     * The lockout question every login path and the middleware ask. No linked
     * employee — admins, integrators, SSO-only accounts — is never locked.
     */
    public function isLeaverLocked(AppUser $user): bool
    {
        if (array_key_exists($user->getKey(), $this->leaverMemo)) {
            return $this->leaverMemo[$user->getKey()];
        }

        $employee = Employee::query()->where('user_id', $user->getKey())->first();

        return $this->leaverMemo[$user->getKey()]
            = $employee !== null && $employee->hasLeftOnOrBefore(now()->toDateString());
    }

    /**
     * The login box accepts the email address or the employee code. A code
     * resolves to the linked user's address so every downstream guard keeps
     * speaking email; unknown codes answer null and the caller fails exactly
     * like an unknown address.
     */
    public function resolveEmail(string $login): ?string
    {
        $login = trim($login);

        if ($login === '') {
            return null;
        }

        if (filter_var($login, FILTER_VALIDATE_EMAIL)) {
            return Str::lower($login);
        }

        $employee = Employee::query()->where('employee_id', $login)->first()
            ?? Employee::query()->where('employee_id', Str::upper($login))->first();

        return $employee?->user?->email;
    }

    /**
     * Keep the login address following the employee address — but only when
     * the login address is ours to move: a placeholder, or the exact address
     * the employee row carried before this edit. An admin-set address is
     * never overwritten, and a collision is never created.
     */
    public function syncEmail(Employee $employee, ?string $previousEmail = null): void
    {
        $user = $employee->user;

        if ($user === null) {
            return;
        }

        $new = Str::lower(trim((string) ($employee->email ?? '')));

        if ($new === '' || $new === Str::lower((string) $user->email)) {
            return;
        }

        $oursToMove = $this->isPlaceholderEmail((string) $user->email)
            || Str::lower((string) $user->email) === Str::lower(trim((string) ($previousEmail ?? '')));

        if (! $oursToMove || User::query()->where('email', $new)->exists()) {
            return;
        }

        $user->update(['email' => $new]);
    }

    public function isPlaceholderEmail(string $email): bool
    {
        return str_ends_with(Str::lower($email), '@'.config('hrm.logins.placeholder_domain', 'employees.local'));
    }

    private function placeholderEmail(Employee $employee): string
    {
        return Str::lower($employee->employee_id).'@'.config('hrm.logins.placeholder_domain', 'employees.local');
    }

    private function tempPassword(): string
    {
        return Str::random(max(8, (int) config('hrm.logins.temp_password_length', 12)));
    }
}
