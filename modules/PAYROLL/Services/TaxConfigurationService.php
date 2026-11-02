<?php

namespace Modules\PAYROLL\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\PAYROLL\Models\PayrollTaxAudit;
use Modules\PAYROLL\Models\TaxConfiguration;
use Modules\PAYROLL\Models\TaxSlab;

final class TaxConfigurationService
{
    public function current(): TaxConfiguration
    {
        return TaxConfiguration::query()->with('slabs')->where('is_active', true)->latest('id')->first()
            ?? TaxConfiguration::query()->create([
                'name' => 'Default', 'mode' => 'progressive', 'is_active' => true,
                'effective_from' => now()->toDateString(),
            ]);
    }

    public function update(array $data, ?int $actorId = null, ?string $reason = null): TaxConfiguration
    {
        $effectiveFrom = Carbon::parse($data['effective_from'] ?? now()->toDateString())->toDateString();
        $effectiveTo = !empty($data['effective_to']) ? Carbon::parse($data['effective_to'])->toDateString() : null;
        if ($effectiveTo !== null && $effectiveTo < $effectiveFrom) {
            throw ValidationException::withMessages(['effective_to' => 'The tax configuration end date must be on or after its effective date.']);
        }

        $slabs = $data['mode'] === 'progressive' ? ($data['slabs'] ?? []) : [];
        if ($data['mode'] === 'progressive' && $slabs === []) {
            throw ValidationException::withMessages(['slabs' => 'Progressive tax mode requires at least one slab.']);
        }
        $previousUpper = 0.0;
        foreach ($slabs as $index => $slab) {
            $lower = round((float) $slab['lower_limit'], 2);
            $upper = ($slab['upper_limit'] ?? '') === '' ? null : round((float) $slab['upper_limit'], 2);
            if (abs($lower - $previousUpper) > 0.005 || ($upper !== null && $upper <= $lower) || ($upper === null && $index !== array_key_last($slabs))) {
                throw ValidationException::withMessages(['slabs' => 'Tax slabs must be contiguous, ascending, non-overlapping, and only the final slab may be unlimited.']);
            }
            $previousUpper = $upper ?? $lower;
        }
        if ($data['mode'] === 'progressive' && $slabs !== []) {
            $lastUpper = $slabs[array_key_last($slabs)]['upper_limit'] ?? null;
            if ($lastUpper !== null && $lastUpper !== '') {
                throw ValidationException::withMessages(['slabs' => 'The final progressive tax slab must have no upper limit so all taxable income is covered.']);
            }
        }

        return DB::transaction(function () use ($data, $slabs, $effectiveFrom, $effectiveTo, $actorId, $reason): TaxConfiguration {
            $active = TaxConfiguration::query()->where('is_active', true)->orderByDesc('id')->lockForUpdate()->get();
            $previous = $active->first();
            if ($previous?->effective_from && $effectiveFrom < $previous->effective_from->toDateString()) {
                throw ValidationException::withMessages(['effective_from' => 'Tax policy versions cannot be backdated before the current active version. Choose the current or a future effective date.']);
            }
            $before = $previous ? [
                ...$previous->load('slabs')->only(['id','name','mode','fixed_monthly_tax','annual_exemption','taxable_salary_percent','is_active','effective_from','effective_to']),
                'slabs' => $previous->slabs->map(fn ($slab) => $slab->only(['id','lower_limit','upper_limit','rate_percent','sort_order']))->all(),
            ] : null;

            foreach ($active as $oldVersion) {
                $oldStart = $oldVersion->effective_from?->toDateString();
                $closeAt = $oldStart !== null && $oldStart < $effectiveFrom
                    ? Carbon::parse($effectiveFrom)->subDay()->toDateString()
                    : $effectiveFrom;
                $oldEnd = $oldVersion->effective_to?->toDateString();
                if ($oldEnd !== null && $oldEnd < $closeAt) $closeAt = $oldEnd;
                $oldVersion->update(['is_active' => false, 'effective_to' => $closeAt]);
            }

            $configuration = TaxConfiguration::query()->create([
                'name' => $data['name'] ?? 'Default',
                'mode' => $data['mode'],
                'fixed_monthly_tax' => $data['fixed_monthly_tax'],
                'annual_exemption' => $data['annual_exemption'],
                'taxable_salary_percent' => $data['taxable_salary_percent'],
                'is_active' => true,
                'effective_from' => $effectiveFrom,
                'effective_to' => $effectiveTo,
            ]);

            foreach ($slabs as $order => $slab) {
                TaxSlab::query()->create([
                    'tax_configuration_id' => $configuration->id,
                    'lower_limit' => $slab['lower_limit'],
                    'upper_limit' => ($slab['upper_limit'] ?? '') !== '' ? $slab['upper_limit'] : null,
                    'rate_percent' => $slab['rate_percent'],
                    'sort_order' => $order,
                ]);
            }

            $configuration->load('slabs');
            PayrollTaxAudit::query()->create([
                'event_type' => 'configuration_saved',
                'subject_id' => $configuration->id,
                'change_reason' => $reason,
                'before_json' => $before,
                'after_json' => [
                    ...$configuration->only(['id','name','mode','fixed_monthly_tax','annual_exemption','taxable_salary_percent','is_active','effective_from','effective_to']),
                    'slabs' => $configuration->slabs->map(fn ($slab) => $slab->only(['id','lower_limit','upper_limit','rate_percent','sort_order']))->all(),
                ],
                'actor_id' => $actorId,
                'occurred_at' => now(),
            ]);

            return $configuration;
        }, 3);
    }
}
