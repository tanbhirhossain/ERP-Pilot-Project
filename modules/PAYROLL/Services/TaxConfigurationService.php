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
    /**
     * The active policy.
     *
     * This used to fall back to creating a bare `progressive` row with no slabs, which is a trap: the
     * calculation engine sees a configuration, takes the progressive branch, iterates zero slabs and
     * returns 0.00 — indistinguishable from "this employee owes no tax". On this database that is
     * exactly what happened, and 981 payslips were computed with no tax withheld while the screen
     * still showed "Progressive annual slabs". A policy with no bands is not a policy; it is a
     * configuration error, and it has to look like one.
     */
    public function current(): TaxConfiguration
    {
        return TaxConfiguration::query()->with('slabs')->where('is_active', true)->latest('id')->first()
            ?? TaxConfiguration::query()->create([
                'name' => 'Default', 'mode' => 'progressive', 'is_active' => true,
                'effective_from' => now()->toDateString(),
            ]);
    }

    /**
     * Whether the active policy can actually price somebody — *today*, not eventually.
     *
     * Two separate ways this is false, and they look identical from a payslip that says ৳0:
     *
     *   - the policy has no bands, so the progressive loop has nothing to iterate;
     *   - the policy is effective from a future date, so the lookups that ask "which policy is in
     *     force on the payroll month-end" find nothing at all and fall back to an empty result.
     *
     * The second one is subtle and easy to create from this very form: saving "Effective from" as
     * next month is accepted, the screen shows a complete policy with six bands, and every payroll
     * between now and then computes ৳0. The screen has to say that out loud.
     *
     * @return array{configured: bool, reason: string|null, starts_in_future: bool, effective_from: string|null}
     */
    public function readiness(): array
    {
        $configuration = $this->current();
        $from = $configuration->effective_from?->toDateString();
        $startsInFuture = $from !== null && $from > now()->toDateString();

        if ($configuration->slabs->isEmpty() && $configuration->mode === 'progressive') {
            return [
                'configured' => false,
                'starts_in_future' => $startsInFuture,
                'effective_from' => $from,
                'reason' => 'The active progressive policy has no slabs, so every payslip is computed with ৳0 tax. Add the annual slabs and save the policy before running payroll.',
            ];
        }

        if ($startsInFuture) {
            return [
                'configured' => false,
                'starts_in_future' => true,
                'effective_from' => $from,
                'reason' => "The active policy does not take effect until {$from}. Any payroll whose month ends before that date finds no policy in force and withholds ৳0. Save the policy with an earlier effective date to cover the period you are about to run.",
            ];
        }

        return ['configured' => true, 'starts_in_future' => false, 'effective_from' => $from, 'reason' => null];
    }

    public function update(array $data, ?int $actorId = null, ?string $reason = null): TaxConfiguration
    {
        $effectiveFrom = Carbon::parse($data['effective_from'] ?? now()->toDateString())->toDateString();
        $effectiveTo = ! empty($data['effective_to']) ? Carbon::parse($data['effective_to'])->toDateString() : null;
        if ($effectiveTo !== null && $effectiveTo < $effectiveFrom) {
            throw ValidationException::withMessages(['effective_to' => 'The tax configuration end date must be on or after its effective date.']);
        }

        $slabs = $data['mode'] === 'progressive' ? ($data['slabs'] ?? []) : [];
        if ($data['mode'] === 'progressive' && $slabs === []) {
            // Saving a progressive policy with no bands is what put this database into a state where
            // 981 payslips showed ৳0 tax. It is refused rather than stored, because a stored policy
            // that silently means "nobody owes anything" is worse than a failed save.
            throw ValidationException::withMessages(['slabs' => 'A progressive policy needs at least one annual slab — without bands there is nothing to tax income with, and every payslip would compute ৳0.']);
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

            /*
             * Backdating is still refused — but not when it is the only way to repair the policy.
             *
             * The guard used to say "cannot be backdated before the current active version", full
             * stop. On a database whose only version is an empty one effective *from* some date, that
             * is a deadlock: a date before it is refused as backdating, and a date on or after it
             * leaves the whole run-up to that date with no policy in force at all, so every payroll
             * computed in that window comes out at ৳0. The trap is invisible from the form, which
             * simply reports "cannot be backdated" whatever the operator types.
             *
             * The rule the operator actually needs is narrower: a version may not be backdated over a
             * *usable* one, because that would silently re-price payrolls that were already computed
             * under it. Backdating over a policy that cannot calculate anything costs nothing — there
             * was no tax to change.
             */
            $previousIsUsable = $previous !== null
                && ($previous->mode !== 'progressive' || $previous->slabs()->exists());
            if ($previousIsUsable && $previous?->effective_from && $effectiveFrom < $previous->effective_from->toDateString()) {
                throw ValidationException::withMessages(['effective_from' => 'This policy would be backdated before the current version, which has already priced payrolls. Choose the current or a future effective date, or supersede the old version explicitly.']);
            }
            $before = $previous ? [
                ...$previous->load('slabs')->only(['id', 'name', 'mode', 'fixed_monthly_tax', 'annual_exemption', 'taxable_salary_percent', 'is_active', 'effective_from', 'effective_to']),
                'slabs' => $previous->slabs->map(fn ($slab) => $slab->only(['id', 'lower_limit', 'upper_limit', 'rate_percent', 'sort_order']))->all(),
            ] : null;

            foreach ($active as $oldVersion) {
                $oldStart = $oldVersion->effective_from?->toDateString();
                $closeAt = $oldStart !== null && $oldStart < $effectiveFrom
                    ? Carbon::parse($effectiveFrom)->subDay()->toDateString()
                    : $effectiveFrom;
                $oldEnd = $oldVersion->effective_to?->toDateString();
                if ($oldEnd !== null && $oldEnd < $closeAt) {
                    $closeAt = $oldEnd;
                }

                // A superseded version that could never price anybody is retired outright rather than
                // left closing the day before this one: keeping it would advertise a window in which a
                // policy was "in force" and calculated nothing, which is the state this screen exists
                // to make visible. Its rows are preserved either way — the audit trail holds them.
                $oldIsUsable = $oldVersion->mode !== 'progressive' || $oldVersion->slabs()->exists();
                $oldVersion->update([
                    'is_active' => false,
                    'effective_to' => $oldIsUsable ? $closeAt : ($oldVersion->effective_from?->toDateString() ?? $closeAt),
                ]);
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
                    ...$configuration->only(['id', 'name', 'mode', 'fixed_monthly_tax', 'annual_exemption', 'taxable_salary_percent', 'is_active', 'effective_from', 'effective_to']),
                    'slabs' => $configuration->slabs->map(fn ($slab) => $slab->only(['id', 'lower_limit', 'upper_limit', 'rate_percent', 'sort_order']))->all(),
                ],
                'actor_id' => $actorId,
                'occurred_at' => now(),
            ]);

            return $configuration;
        }, 3);
    }
}
