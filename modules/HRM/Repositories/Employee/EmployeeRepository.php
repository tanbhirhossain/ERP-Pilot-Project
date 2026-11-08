<?php

namespace Modules\HRM\Repositories\Employee;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\CORE\Repositories\Concerns\BuildsDatatableQueries;
use Modules\CORE\Support\DataTableQuery;
use Modules\HRM\Interfaces\Employee\EmployeeRepositoryInterface;
use Modules\HRM\Models\DeviceUser;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Models\Employee\EmployeeEducation;
use Modules\HRM\Models\Employee\EmployeeExperience;
use Modules\HRM\Models\Employee\EmployeeFallbackOffDay;
use Modules\HRM\Models\Employee\EmployeePolicy;
use Modules\HRM\Models\Employee\EmployeeProfile;
use Modules\HRM\Models\Employee\EmployeeSalary;
use Modules\HRM\Models\Employee\EmployeeSkill;

class EmployeeRepository implements EmployeeRepositoryInterface
{
    use BuildsDatatableQueries;

    public function paginate(DataTableQuery $dt): LengthAwarePaginator
    {
        $q = $this->baseQuery();
        $this->applyDataTable($q, $dt, $this->searchColumns(), $this->sortable(), $this->filters());

        return $q->paginate($dt->perPage, ['*'], 'page', $dt->page)->withQueryString();
    }

    public function exportBuilder(DataTableQuery $dt): Builder
    {
        $q = $this->baseQuery();
        $this->applyDataTable($q, $dt, $this->searchColumns(), $this->sortable(), $this->filters());

        return $q;
    }

    public function options(?string $search = null, int $limit = 25): Collection
    {
        return Employee::query()->when($search, fn (Builder $q, $t) => $q->where(function ($g) use ($t) {
            $needle = '%'.addcslashes($t, '%_').'%';
            $g->where('employee_id', 'like', $needle)->orWhere('full_name', 'like', $needle)->orWhere('first_name', 'like', $needle)->orWhere('last_name', 'like', $needle);
        }))->orderBy('full_name')->limit($limit)->get(['id', 'employee_id', 'full_name', 'first_name', 'last_name'])->map(fn ($e) => ['value' => $e->employee_id, 'label' => ($e->full_name ?? trim($e->first_name.' '.$e->last_name)).' ('.$e->employee_id.')', 'description' => $e->employee_id])->values();
    }

    public function findOrFail(int $id): Employee
    {
        return Employee::with(['organization', 'department', 'designation', 'user', 'profile', 'policy', 'salaries', 'educations', 'experiences', 'skills', 'supervisors', 'deviceUsers'])->findOrFail($id);
    }

    public function findByEmployeeId(string $employeeId): ?Employee
    {
        return Employee::where('employee_id', $employeeId)->first();
    }

    public function create(array $data): Employee
    {
        return DB::transaction(function () use ($data) {
            // Lock employees table for ID generation to prevent race
            if (empty($data['employee_id'])) {
                $last = Employee::lockForUpdate()->orderBy('id', 'desc')->first();
                $num = $last ? intval(preg_replace('/\D/', '', $last->employee_id)) : 0;
                $data['employee_id'] = 'EMP-'.str_pad($num + 1, 4, '0', STR_PAD_LEFT);
                // Double-check uniqueness in case of manual entry collision
                while (Employee::where('employee_id', $data['employee_id'])->exists()) {
                    $num++;
                    $data['employee_id'] = 'EMP-'.str_pad($num + 1, 4, '0', STR_PAD_LEFT);
                }
            }
            if (empty($data['full_name']) && ! empty($data['first_name'])) {
                $data['full_name'] = trim(($data['first_name'] ?? '').' '.($data['last_name'] ?? ''));
            }
            $profile = $data['profile'] ?? null;
            $salary = $data['salary'] ?? null;
            $policy = $data['policy'] ?? null;
            $fallbackOffDay = is_array($policy) && array_key_exists('fallback_off_day', $policy) ? $policy['fallback_off_day'] : null;
            if (is_array($policy)) {
                unset($policy['fallback_off_day']);
            }
            $educations = $data['educations'] ?? [];
            $experiences = $data['experiences'] ?? [];
            $skills = $data['skills'] ?? [];
            // The form keeps one blank row ready for input. Never persist a skill without its required database key.
            $skills = array_values(array_filter($skills, fn (array $skill): bool => trim((string) ($skill['skill_name'] ?? '')) !== ''));
            unset($data['profile'],$data['salary'],$data['policy'],$data['educations'],$data['experiences'],$data['skills'],$data['supervisors']);
            $employee = Employee::create($data);
            // Professional: sync biometric_id to device_users (single source of truth)
            if (! empty($data['biometric_id'])) {
                DeviceUser::updateOrCreate(
                    ['device_user_id' => trim((string) $data['biometric_id'])],
                    ['employee_id' => $employee->employee_id, 'device_id' => null, 'uid' => trim((string) $data['biometric_id']), 'name' => $employee->full_name]
                );
            }
            if ($profile && array_filter($profile)) {
                $profile['employee_id'] = $employee->employee_id;
                EmployeeProfile::create(array_filter($profile, fn ($v) => $v !== null && $v !== ''));
            }
            if ($policy && array_filter($policy)) {
                $policy['employee_id'] = $employee->employee_id;
                EmployeePolicy::create($policy);
            }
            if ($fallbackOffDay !== null && $fallbackOffDay !== '') {
                EmployeeFallbackOffDay::updateOrCreate(['employee_id' => $employee->employee_id], ['day_of_week' => (int) $fallbackOffDay]);
            }
            if ($salary && array_filter($salary)) {
                $salary['employee_id'] = $employee->employee_id;
                EmployeeSalary::create($salary);
            }
            foreach ($educations as $ed) {
                if (array_filter($ed)) {
                    $ed['employee_id'] = $employee->employee_id;
                    EmployeeEducation::create($ed);
                }
            }
            foreach ($experiences as $ex) {
                if (array_filter($ex)) {
                    $ex['employee_id'] = $employee->employee_id;
                    EmployeeExperience::create($ex);
                }
            }
            foreach ($skills as $sk) {
                if (array_filter($sk)) {
                    $sk['employee_id'] = $employee->employee_id;
                    EmployeeSkill::create($sk);
                }
            }

            return $employee;
        });
    }

    public function update(Employee $e, array $data): Employee
    {
        return DB::transaction(function () use ($e, $data) {
            if (! empty($data['first_name']) || ! empty($data['last_name'])) {
                $first = $data['first_name'] ?? $e->first_name;
                $last = $data['last_name'] ?? $e->last_name;
                $data['full_name'] = trim($first.' '.$last);
            }
            $profile = $data['profile'] ?? null;
            $policy = $data['policy'] ?? null;
            $hasFallbackOffDay = is_array($policy) && array_key_exists('fallback_off_day', $policy);
            $fallbackOffDay = $hasFallbackOffDay ? $policy['fallback_off_day'] : null;
            if (is_array($policy)) {
                unset($policy['fallback_off_day']);
            }
            $salary = $data['salary'] ?? null;
            $educations = $data['educations'] ?? null;
            $experiences = $data['experiences'] ?? null;
            $skills = $data['skills'] ?? null;
            // On edit, blank placeholder rows are not persisted; omitting their ids also removes cleared skill rows.
            if (is_array($skills)) {
                $skills = array_values(array_filter($skills, fn (array $skill): bool => trim((string) ($skill['skill_name'] ?? '')) !== ''));
            }
            unset($data['profile'],$data['policy'],$data['salary'],$data['educations'],$data['experiences'],$data['skills'],$data['supervisors']);
            $oldBiometric = $e->biometric_id;
            $e->fill($data);
            $e->save();
            // Professional: keep device_users in sync with employees.biometric_id
            if (array_key_exists('biometric_id', $data)) {
                $newBio = $data['biometric_id'] ? trim((string) $data['biometric_id']) : null;
                if ($newBio && $newBio !== $oldBiometric) {
                    // Remove stale mapping for old biometric_id if it belonged to this employee
                    if ($oldBiometric) {
                        DeviceUser::where('device_user_id', $oldBiometric)->where('employee_id', $e->employee_id)->delete();
                    }
                    DeviceUser::updateOrCreate(
                        ['device_user_id' => $newBio],
                        ['employee_id' => $e->employee_id, 'device_id' => null, 'uid' => $newBio, 'name' => $e->full_name]
                    );
                } elseif (! $newBio && $oldBiometric) {
                    // Cleared biometric_id -> remove device_user mapping
                    DeviceUser::where('device_user_id', $oldBiometric)->where('employee_id', $e->employee_id)->delete();
                }
            }
            // Profile
            if ($profile !== null) {
                $prof = EmployeeProfile::where('employee_id', $e->employee_id)->first();
                if ($prof) {
                    $prof->update($profile);
                } else {
                    $profile['employee_id'] = $e->employee_id;
                    EmployeeProfile::create($profile);
                }
            }
            // Policy
            if ($policy !== null) {
                $pol = EmployeePolicy::where('employee_id', $e->employee_id)->first();
                if ($pol) {
                    $pol->update($policy);
                } elseif ($policy) {
                    $policy['employee_id'] = $e->employee_id;
                    EmployeePolicy::create($policy);
                }
            }
            if ($hasFallbackOffDay) {
                if ($fallbackOffDay === null || $fallbackOffDay === '') {
                    EmployeeFallbackOffDay::where('employee_id', $e->employee_id)->delete();
                } else {
                    EmployeeFallbackOffDay::updateOrCreate(['employee_id' => $e->employee_id], ['day_of_week' => (int) $fallbackOffDay]);
                }
            }
            // Salary - upsert current
            if ($salary !== null && array_filter($salary)) {
                $sal = EmployeeSalary::where('employee_id', $e->employee_id)->where('is_current', true)->first();
                if ($sal) {
                    $sal->update($salary);
                } else {
                    $salary['employee_id'] = $e->employee_id;
                    EmployeeSalary::create($salary);
                }
            }
            // Educations - full replace (delete missing ids)
            if ($educations !== null) {
                $keepIds = collect($educations)->pluck('id')->filter()->all();
                EmployeeEducation::where('employee_id', $e->employee_id)->when(! empty($keepIds), fn ($q) => $q->whereNotIn('id', $keepIds))->when(empty($keepIds) && ! empty($educations), fn ($q) => $q->delete());
                if (empty($keepIds) && empty($educations)) { /* keep all if not sent? but null means clear? skip delete */
                }
                // Actually for update, we sync: delete where id not in keepIds only if educations provided
                if ($keepIds) {
                    EmployeeEducation::where('employee_id', $e->employee_id)->whereNotIn('id', $keepIds)->delete();
                } elseif (empty($keepIds) && count($educations) === 0) {
                    // If empty array, delete all
                    EmployeeEducation::where('employee_id', $e->employee_id)->delete();
                }
                foreach ($educations as $ed) {
                    if (empty(array_filter($ed))) {
                        continue;
                    }
                    if (! empty($ed['id'])) {
                        $row = EmployeeEducation::where('id', $ed['id'])->where('employee_id', $e->employee_id)->first();
                        if ($row) {
                            $row->update($ed);
                        }
                    } else {
                        $ed['employee_id'] = $e->employee_id;
                        EmployeeEducation::create($ed);
                    }
                }
            }
            // Experiences
            if ($experiences !== null) {
                $keepIds = collect($experiences)->pluck('id')->filter()->all();
                if ($keepIds) {
                    EmployeeExperience::where('employee_id', $e->employee_id)->whereNotIn('id', $keepIds)->delete();
                } elseif (empty($keepIds) && count($experiences) === 0) {
                    EmployeeExperience::where('employee_id', $e->employee_id)->delete();
                }
                foreach ($experiences as $ex) {
                    if (empty(array_filter($ex))) {
                        continue;
                    }
                    if (! empty($ex['id'])) {
                        $row = EmployeeExperience::where('id', $ex['id'])->where('employee_id', $e->employee_id)->first();
                        if ($row) {
                            $row->update($ex);
                        }
                    } else {
                        $ex['employee_id'] = $e->employee_id;
                        EmployeeExperience::create($ex);
                    }
                }
            }
            // Skills
            if ($skills !== null) {
                $keepIds = collect($skills)->pluck('id')->filter()->all();
                if ($keepIds) {
                    EmployeeSkill::where('employee_id', $e->employee_id)->whereNotIn('id', $keepIds)->delete();
                } elseif (empty($keepIds) && count($skills) === 0) {
                    EmployeeSkill::where('employee_id', $e->employee_id)->delete();
                }
                foreach ($skills as $sk) {
                    if (empty(array_filter($sk))) {
                        continue;
                    }
                    if (! empty($sk['id'])) {
                        $row = EmployeeSkill::where('id', $sk['id'])->where('employee_id', $e->employee_id)->first();
                        if ($row) {
                            $row->update($sk);
                        }
                    } else {
                        $sk['employee_id'] = $e->employee_id;
                        EmployeeSkill::create($sk);
                    }
                }
            }

            return $e->refresh()->load(['profile', 'policy', 'salaries', 'educations', 'experiences', 'skills']);
        });
    }

    public function delete(Employee $e): void
    {
        DB::transaction(fn () => $e->delete());
    }

    private function baseQuery(): Builder
    {
        return Employee::query()->with(['department:id,name', 'designation:id,name', 'organization:id,name']);
    }

    private function searchColumns(): array
    {
        return ['employee_id', 'biometric_id', 'full_name', 'first_name', 'email', 'phone'];
    }

    private function sortable(): array
    {
        return ['id' => 'id', 'employee_id' => 'employee_id', 'full_name' => 'full_name', 'created_at' => 'created_at', 'joining_date' => 'joining_date'];
    }

    private function filters(): array
    {
        return ['department_id' => fn (Builder $q, string $v) => $q->where('department_id', $v), 'designation_id' => fn (Builder $q, string $v) => $q->where('designation_id', $v), 'status' => fn (Builder $q, string $v) => $q->where('status', $v)];
    }
}
