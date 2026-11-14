<?php

namespace Modules\ACCOUNTS\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\ACCOUNTS\Models\DepreciationRun;
use Modules\ACCOUNTS\Models\FixedAsset;

/**
 * F15 — the depreciation engine.
 *
 * Two responsibilities: a pure per-asset calculation (`computeCharge`) that decides what a month's
 * charge is, and a transactional `run()` that charges every in-use asset for a period, writes the
 * per-asset lines, and posts **one** balanced journal for the whole month (Dr depreciation expense,
 * Cr accumulated depreciation, grouped by category accounts).
 *
 * The arithmetic is done in integer paise, exactly like `VatReturnService`, so a re-run is bit-for-bit
 * reproducible and no float drift accumulates across a hundred months.
 *
 * Conventions, all deliberate and pinned by `Phase3FixedAssetsTest`:
 *
 *   - **Straight line**: charge = (cost − salvage) / life / 12 per month.
 *   - **Declining balance**: charge = NBV × (factor / life) / 12 per month. NBV-based, so it self-limits.
 *   - **Pro-rata by days**: the acquisition month (and a disposal month) is charged
 *     `charge × in-service-days ÷ days-in-month`, so a machine bought on the 15th depreciates for half
 *     a month — the whole asset book does not jump a half-month of expense on day one.
 *   - **Never below salvage**: the accumulated charge is capped at `cost − salvage`; once reached the
 *     asset is `fully_depreciated` and further periods charge zero.
 *   - **One run per period**: a second `run()` for a period already run is refused (and each
 *     (asset, period) line is unique anyway), so the month cannot be depreciated twice.
 */
class DepreciationService
{
    public function __construct(private readonly JournalService $journals) {}

    /** @return int integer paise, rounded half-up */
    private function cents(float $v): int
    {
        return (int) round($v * 100);
    }

    /** @return float dollars from paise */
    private function dollars(int $cents): float
    {
        return $cents / 100;
    }

    private function assertPeriod(string $period): void
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $period)) {
            abort(422, 'Period must be YYYY-MM.');
        }
    }

    /** Resolve the effective method / life / salvage for an asset, defaulting to its category. */
    public function effectiveParams(FixedAsset $asset): array
    {
        $cat = $asset->category;

        $method = $asset->method ?: ($cat?->default_method ?? 'straight_line');
        $life = (int) ($asset->useful_life_years ?: ($cat?->default_useful_life_years ?? 5));
        $factor = (float) ($asset->db_factor ?: ($cat?->default_db_factor ?? 2));
        $salvage = $asset->salvage_value !== null
            ? (float) $asset->salvage_value
            : round((float) $asset->cost * ((float) ($cat?->default_salvage_pct ?? 0)) / 100, 2);

        return [
            'method' => in_array($method, ['straight_line', 'declining_balance'], true) ? $method : 'straight_line',
            'life_years' => max(1, $life),
            'db_factor' => max(0.01, $factor),
            'salvage' => max(0, $salvage),
        ];
    }

    /**
     * What this asset should be charged for one period.
     *
     * Reads only the asset's current running balances plus its parameters — it never writes, so it can
     * be called for a preview, a "what-if", or a re-run without side effects.
     *
     * @return array{ok: bool, charge: float, opening_nbv: float, accumulated_after: float, closing_nbv: float, fully: bool, method: string, basis: string}
     */
    public function computeCharge(FixedAsset $asset, string $period): array
    {
        $this->assertPeriod($period);

        $params = $this->effectiveParams($asset);

        $cost = $this->cents((float) $asset->cost);
        $salvage = $this->cents($params['salvage']);
        $priorAccum = $this->cents((float) $asset->accumulated_dep);

        $depreciable = $cost - $salvage;          // total that can ever be written off
        $opening = $cost - $priorAccum;           // NBV at period start
        $remaining = $depreciable - $priorAccum;  // what is left to charge

        $zero = [
            'ok' => false, 'charge' => 0.0, 'opening_nbv' => $this->dollars(max(0, $opening)),
            'accumulated_after' => $this->dollars($priorAccum), 'closing_nbv' => $this->dollars(max(0, $opening)),
            'fully' => $remaining <= 0, 'method' => $params['method'], 'basis' => '',
        ];

        if ($remaining <= 0 || $opening <= 0) {
            return $zero;
        }

        $periodStart = Carbon::createFromFormat('Y-m-d', $period.'-01')->startOfDay();
        $periodEnd = $periodStart->copy()->endOfMonth()->startOfDay();
        $daysInMonth = $periodStart->daysInMonth;

        // When the asset enters service (defaults to the purchase date).
        $inService = ($asset->in_service_date ?? $asset->purchase_date)?->copy()->startOfDay();
        if ($inService === null || $inService->gt($periodEnd)) {
            return $zero; // not yet placed in service in this month
        }

        $start = $inService->gt($periodStart) ? $inService : $periodStart;

        // When it leaves service (disposal stops charging).
        $end = $periodEnd;
        $disposed = $asset->disposed_date?->copy()->startOfDay();
        if ($disposed !== null && $disposed->lt($periodEnd)) {
            $end = $disposed;
        }

        if ($start->gt($end)) {
            return $zero;
        }

        $ownedDays = (int) $start->diffInDays($end) + 1;   // inclusive
        $fraction = $ownedDays / $daysInMonth;

        if ($params['method'] === 'declining_balance') {
            $rate = $params['db_factor'] / $params['life_years'];
            $full = $opening * $rate / 12;                   // paise, float
        } else {
            $full = $depreciable / ($params['life_years'] * 12); // paise, float
        }

        $charge = (int) round($full * $fraction);
        if ($charge > $remaining) {
            $charge = $remaining;                              // cap so NBV never dips below salvage
        }
        if ($charge <= 0) {
            return $zero;
        }

        $accumAfter = $priorAccum + $charge;
        $closing = $cost - $accumAfter;
        $fully = ($depreciable - $accumAfter) <= 0;

        $basis = $params['method'] === 'declining_balance'
            ? sprintf('%.0f%%/%dy declining on NBV, %d/%d days', ($params['db_factor'] / $params['life_years']) * 100, $params['life_years'], $ownedDays, $daysInMonth)
            : sprintf('SL %dy, %d/%d days', $params['life_years'], $ownedDays, $daysInMonth);

        return [
            'ok' => true,
            'charge' => $this->dollars($charge),
            'opening_nbv' => $this->dollars($opening),
            'accumulated_after' => $this->dollars($accumAfter),
            'closing_nbv' => $this->dollars($closing),
            'fully' => $fully,
            'method' => $params['method'],
            'basis' => $basis,
        ];
    }

    /**
     * Capitalise a draft asset: freeze the category defaults onto the row and set the opening NBV,
     * then flip it to `in_use` so the next run can pick it up. Salvage is resolved from the category
     * percentage only when the asset did not state one, so a manual override is never clobbered.
     */
    public function capitalize(FixedAsset $asset, ?int $userId = null): FixedAsset
    {
        if ($asset->status === 'in_use') {
            return $asset; // already live
        }
        if ($asset->status === 'disposed') {
            abort(422, 'A disposed asset cannot be capitalised.');
        }
        if ($this->cents((float) $asset->cost) <= 0) {
            abort(422, 'An asset needs a cost greater than zero before it can be capitalised.');
        }

        $params = $this->effectiveParams($asset);
        $salvage = $asset->salvage_value !== null ? (float) $asset->salvage_value : $params['salvage'];
        if ($this->cents($salvage) > $this->cents((float) $asset->cost)) {
            abort(422, 'Salvage value cannot exceed cost.');
        }

        $asset->forceFill([
            'method' => $params['method'],
            'useful_life_years' => $params['life_years'],
            'db_factor' => $params['db_factor'],
            'salvage_value' => $salvage,
            'in_service_date' => $asset->in_service_date ?? $asset->purchase_date,
            'accumulated_dep' => 0,
            'net_book_value' => (float) $asset->cost,
            'status' => 'in_use',
        ])->save();

        return $asset->refresh();
    }

    /**
     * Dispose an asset. This writes it off the depreciable register (no further run charges it) and
     * records proceeds; the actual bank/deposal-account journal is left to a normal JV so the disposal
     * account set does not have to be pre-configured on every category.
     */
    public function dispose(FixedAsset $asset, string $date, ?float $proceeds, ?string $notes = null): FixedAsset
    {
        if ($asset->status === 'disposed') {
            abort(422, 'Asset is already disposed.');
        }
        $disposed = Carbon::parse($date)->startOfDay();
        if ($asset->purchase_date && $disposed->lt($asset->purchase_date->copy()->startOfDay())) {
            abort(422, 'Disposal date cannot precede the purchase date.');
        }

        $asset->forceFill([
            'status' => 'disposed',
            'disposed_date' => $disposed->toDateString(),
            'disposal_proceeds' => $proceeds !== null ? round($proceeds, 2) : null,
            'notes' => $notes ?? $asset->notes,
        ])->save();

        return $asset->refresh();
    }

    /**
     * Depreciate every in-use asset for a period and post one balanced journal. All-or-nothing:
     * a misconfigured category (a group header or inactive account) throws before anything is written,
     * so the month never posts half a journal.
     */
    public function run(string $period, ?int $userId = null, ?string $notes = null): DepreciationRun
    {
        $this->assertPeriod($period);

        if (DepreciationRun::where('period', $period)->exists()) {
            abort(422, "Depreciation for {$period} has already been run.");
        }

        $runDate = Carbon::createFromFormat('Y-m-d', $period.'-01')->endOfMonth()->toDateString();
        $runNo = 'DEP-'.str_replace('-', '', $period);

        return DB::transaction(function () use ($period, $runDate, $runNo, $userId, $notes): DepreciationRun {
            /** @var array<int,int> $expenseByAccount account_id => paise */
            $expenseByAccount = [];
            /** @var array<int,int> $accumByAccount */
            $accumByAccount = [];
            $lines = [];
            $totalCents = 0;

            $assets = FixedAsset::depreciable()
                ->whereDoesntHave('depreciations', fn ($q) => $q->where('period', $period))
                ->with('category')
                ->orderBy('id')
                ->get();

            foreach ($assets as $asset) {
                $r = $this->computeCharge($asset, $period);
                if (! $r['ok']) {
                    continue;
                }

                $cat = $asset->category;
                $chargeCents = $this->cents($r['charge']);

                $expenseByAccount[$cat->dep_expense_account_id] = ($expenseByAccount[$cat->dep_expense_account_id] ?? 0) + $chargeCents;
                $accumByAccount[$cat->accum_dep_account_id] = ($accumByAccount[$cat->accum_dep_account_id] ?? 0) + $chargeCents;
                $totalCents += $chargeCents;

                $lines[] = [
                    'asset' => $asset,
                    'charge' => $chargeCents,
                    'opening' => $r['opening_nbv'],
                    'accum' => $r['accumulated_after'],
                    'closing' => $r['closing_nbv'],
                    'method' => $r['method'],
                    'fully' => $r['fully'],
                    'basis' => $r['basis'],
                ];
            }

            $run = DepreciationRun::create([
                'run_no' => $runNo,
                'period' => $period,
                'run_date' => $runDate,
                'total_amount' => 0,
                'line_count' => 0,
                'status' => 'posted',
                'notes' => $notes,
                'created_by' => $userId,
                'posted_at' => now(),
            ]);

            if ($lines === []) {
                return $run; // a clean, empty run still closes the period
            }

            // Build the balanced journal: debit each expense account, credit each accumulated account.
            $journalLines = [];
            foreach ($expenseByAccount as $accountId => $cents) {
                $journalLines[] = ['account_id' => $accountId, 'debit' => $this->dollars($cents), 'credit' => 0, 'description' => "Depreciation {$period}"];
            }
            foreach ($accumByAccount as $accountId => $cents) {
                $journalLines[] = ['account_id' => $accountId, 'debit' => 0, 'credit' => $this->dollars($cents), 'description' => "Accum. dep {$period}"];
            }

            $journal = $this->journals->post($this->journals->create([
                'journal_date' => $runDate,
                'type' => 'JV',
                'reference_no' => $runNo,
                'narration' => "Depreciation run {$period} (".count($lines).' assets)',
                'status' => 'draft',
                'lines' => $journalLines,
            ]));

            foreach ($lines as $line) {
                $line['asset']->depreciations()->create([
                    'run_id' => $run->id,
                    'period' => $period,
                    'opening_nbv' => $line['opening'],
                    'charge' => $this->dollars($line['charge']),
                    'accumulated_after' => $line['accum'],
                    'closing_nbv' => $line['closing'],
                    'method' => $line['method'],
                    'basis' => $line['basis'],
                ]);

                $line['asset']->forceFill([
                    'accumulated_dep' => $line['accum'],
                    'net_book_value' => $line['closing'],
                    'status' => $line['fully'] ? 'fully_depreciated' : $line['asset']->status,
                ])->save();
            }

            $run->forceFill([
                'total_amount' => $this->dollars($totalCents),
                'line_count' => count($lines),
                'journal_id' => $journal->id,
            ])->save();

            return $run->refresh();
        });
    }

    /** Aggregate NBV / cost / accum for the register header, in dollars. */
    public function summary(): array
    {
        $assets = FixedAsset::where('status', '!=', 'draft')->get();

        $cost = 0;
        $accum = 0;
        foreach ($assets as $a) {
            $cost += $this->cents((float) $a->cost);
            $accum += $this->cents((float) $a->accumulated_dep);
        }

        return [
            'count' => $assets->count(),
            'cost' => $this->dollars($cost),
            'accumulated' => $this->dollars($accum),
            'nbv' => $this->dollars($cost - $accum),
        ];
    }
}
