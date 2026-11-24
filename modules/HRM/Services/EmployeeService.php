<?php

namespace Modules\HRM\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\CORE\Support\DataTableQuery;
use Modules\HRM\Interfaces\Employee\EmployeeRepositoryInterface;
use Modules\HRM\Models\Employee\Employee;

class EmployeeService
{
    public function __construct(
        private readonly EmployeeRepositoryInterface $repo,
        private readonly EmployeeLoginService $logins,
    ) {}

    public function paginate(DataTableQuery $dt): LengthAwarePaginator
    {
        return $this->repo->paginate($dt);
    }

    public function options(?string $s = null, int $l = 25): Collection
    {
        return $this->repo->options($s, $l);
    }

    public function findOrFail(int $id): Employee
    {
        return $this->repo->findOrFail($id);
    }

    public function findByEmployeeId(string $eid): ?Employee
    {
        return $this->repo->findByEmployeeId($eid);
    }

    public function create(array $d): Employee
    {
        return $this->createWithLogin($d)['employee'];
    }

    /**
     * Create the employee and provision the login in the same call — but not
     * the same transaction: the repository owns its transaction, and a login
     * failure must never roll back the hire (the backfill command recovers
     * it). The temporary password is returned so the controller can show it
     * once; nobody can read it back afterwards.
     *
     * @return array{employee: Employee, tempPassword: ?string, loginCreated: bool, loginLinked: bool}
     */
    public function createWithLogin(array $d): array
    {
        $employee = $this->repo->create($d);

        if ($employee->user_id !== null) {
            return ['employee' => $employee, 'tempPassword' => null, 'loginCreated' => false, 'loginLinked' => true];
        }

        try {
            $provisioned = $this->logins->provision($employee);
        } catch (\Throwable $e) {
            Log::warning('Employee login provisioning failed.', [
                'employee' => $employee->employee_id,
                'error' => $e->getMessage(),
            ]);
            $provisioned = null;
        }

        return [
            'employee' => $employee,
            'tempPassword' => $provisioned['tempPassword'] ?? null,
            'loginCreated' => (bool) ($provisioned['created'] ?? false),
            'loginLinked' => $provisioned !== null && ! $provisioned['created'],
        ];
    }

    public function update(Employee $e, array $data): Employee
    {
        $previousEmail = $e->email;
        $employee = $this->repo->update($e, $data);
        $employee->refresh();

        $this->logins->syncEmail($employee, $previousEmail);

        // A termination lands here as a status edit, not through the
        // resignation flow, so the leaving moment revokes access too.
        if ($employee->user !== null && $employee->hasLeftOnOrBefore(now()->toDateString())) {
            $this->logins->revokeAccess($employee->user);
        }

        return $employee;
    }

    public function delete(Employee $e): void
    {
        DB::transaction(function () use ($e): void {
            // Provisioned logins die with the employee; hand-linked accounts
            // survive (their access was still revoked: the job ended).
            $this->logins->deleteProvisionedUser($e);
            $this->repo->delete($e);
        });
    }
}
