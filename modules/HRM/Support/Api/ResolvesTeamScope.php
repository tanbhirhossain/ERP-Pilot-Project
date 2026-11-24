<?php

namespace Modules\HRM\Support\Api;

use Modules\CORE\Models\User;
use Modules\HRM\Models\Employee\EmployeeSupervisor;

/**
 * Team scoping for the Phase-3 supervisor/GM/CEO surface.
 *
 * Two visibility tiers, no middle:
 *   - ALL-scope: Super Admin / Administrator / Admin / HR Admin / DGM HR Admin /
 *     General Manager / CEO — the whole organisation.
 *   - Direct-scope: everyone else (typically Supervisors) — only employees
 *     mapped to them in employee_supervisors.
 *
 * `teamCodes()` returns null for ALL-scope, or the (possibly empty) list of
 * direct-report codes. Controllers 403 a target outside the caller's scope.
 */
trait ResolvesTeamScope
{
    /** @var list<string> */
    private function allScopeRoles(): array
    {
        return ['Super Admin', 'Administrator', 'Admin', 'HR Admin', 'DGM HR Admin', 'General Manager', 'CEO'];
    }

    private function hasAllScope(User $user): bool
    {
        return $user->hasAnyRole($this->allScopeRoles());
    }

    /**
     * @return list<string>|null null = whole organisation
     */
    private function teamCodes(User $user): ?array
    {
        if ($this->hasAllScope($user)) {
            return null;
        }

        return EmployeeSupervisor::where('supervisor_id', $user->id)
            ->pluck('employee_id')
            ->map(fn ($code): string => (string) $code)
            ->unique()->values()->all();
    }

    private function inScope(User $user, string $employeeCode): bool
    {
        if ($this->hasAllScope($user)) {
            return true;
        }

        return EmployeeSupervisor::where('supervisor_id', $user->id)
            ->where('employee_id', $employeeCode)
            ->exists();
    }
}
