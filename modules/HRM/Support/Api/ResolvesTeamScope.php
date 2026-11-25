<?php

namespace Modules\HRM\Support\Api;

use Modules\HRM\Interfaces\EmployeeSupervisorRepositoryInterface;

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
 *
 * Mapping reads travel through the supervisor repository interface (resolved
 * from the container: traits take no constructor). Controllers stay free of
 * models.
 */
trait ResolvesTeamScope
{
    /** @var list<string> */
    private function allScopeRoles(): array
    {
        return ['Super Admin', 'Administrator', 'Admin', 'HR Admin', 'DGM HR Admin', 'General Manager', 'CEO'];
    }

    private function hasAllScope($user): bool
    {
        return $user->hasAnyRole($this->allScopeRoles());
    }

    /**
     * @return list<string>|null null = whole organisation
     */
    private function teamCodes($user): ?array
    {
        if ($this->hasAllScope($user)) {
            return null;
        }

        return app(EmployeeSupervisorRepositoryInterface::class)->codesForSupervisor($user->id);
    }

    private function inScope($user, string $employeeCode): bool
    {
        if ($this->hasAllScope($user)) {
            return true;
        }

        return app(EmployeeSupervisorRepositoryInterface::class)->inScope($user->id, $employeeCode);
    }
}
