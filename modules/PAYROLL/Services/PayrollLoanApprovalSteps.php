<?php

namespace Modules\PAYROLL\Services;

use Illuminate\Validation\ValidationException;
use Modules\CORE\Models\Role;

final class PayrollLoanApprovalSteps
{
    /** @return array<int, array{label: string, role: string}> */
    public static function normalize(?array $steps): array
    {
        $steps = $steps ?: [['label' => 'Payroll', 'role' => 'Administrator']];
        $normalized = [];
        foreach ($steps as $step) {
            if (is_array($step)) {
                $label = trim((string) ($step['label'] ?? ''));
                $role = trim((string) ($step['role'] ?? ''));
            } else {
                $raw = trim((string) $step);
                [$label, $role] = array_pad(array_map('trim', explode('=', $raw, 2)), 2, '');
                if ($role === '') $role = $label === 'Payroll' ? 'Administrator' : $label;
            }
            if ($label !== '' && $role !== '') $normalized[] = ['label' => $label, 'role' => $role];
        }
        return $normalized ?: [['label' => 'Payroll', 'role' => 'Administrator']];
    }

    /** @return array<int, array{label: string, role: string}> */
    public static function parseInput(?string $input): array
    {
        $rawSteps = collect(explode(',', $input ?: 'Payroll=Administrator'))
            ->map(fn (string $step): string => trim($step))->filter()->values();
        $steps = [];
        foreach ($rawSteps as $raw) {
            $parts = array_map('trim', explode('=', $raw, 2));
            if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
                throw ValidationException::withMessages(['loan_approval_steps_text' => 'Use Stage name=Role name for every approval tier, for example Manager=Manager, Finance=Finance.']);
            }
            $steps[] = ['label' => $parts[0], 'role' => $parts[1]];
        }

        if ($steps === []) $steps = [['label' => 'Payroll', 'role' => 'Administrator']];
        $labels = array_column($steps, 'label');
        $roles = array_column($steps, 'role');
        if (count($labels) !== count(array_unique($labels))) {
            throw ValidationException::withMessages(['loan_approval_steps_text' => 'Approval tier labels must be unique.']);
        }
        if (count($roles) !== count(array_unique($roles))) {
            throw ValidationException::withMessages(['loan_approval_steps_text' => 'Each approval tier must use a different role.']);
        }
        $existingRoles = Role::query()->where('guard_name', 'web')->whereIn('name', $roles)->pluck('name')->all();
        $missing = array_values(array_diff($roles, $existingRoles));
        if ($missing !== []) {
            throw ValidationException::withMessages(['loan_approval_steps_text' => 'Unknown approval role(s): '.implode(', ', $missing).'.']);
        }
        return $steps;
    }
}
