<?php

namespace Modules\ACCOUNTS\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\ACCOUNTS\Models\AssetCategory;
use Modules\ACCOUNTS\Models\FixedAsset;
use Modules\ACCOUNTS\Services\DepreciationService;

/**
 * F15 — the fixed-asset register.
 *
 * List, capitalise and dispose. Creating an asset stores it as `draft`; "Capitalise" freezes the
 * category defaults onto the row and flips it to `in_use` so the depreciation run picks it up — the
 * two-step is deliberate, so a half-entered asset never enters the ledger. Disposal just leaves the
 * depreciable register (the write-off journal is a normal JV), which is why `dispose` needs no
 * disposal account on the category.
 */
class FixedAssetController extends Controller
{
    public function __construct(private readonly DepreciationService $depreciation) {}

    public function index(Request $r): Response
    {
        $status = $r->input('status');

        $assets = FixedAsset::query()
            ->with(['category:id,name,code', 'supplier:id,name', 'depreciations' => fn ($q) => $q->latest('period')->limit(1)])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->latest('purchase_date')
            ->limit(300)
            ->get();

        return Inertia::render('ACCOUNTS::Assets/Index', [
            'status' => $status,
            'assets' => $assets,
            'summary' => $this->depreciation->summary(),
            'categories' => AssetCategory::active()->orderBy('name')->get()->map(fn ($c) => [
                'id' => $c->id, 'name' => $c->name, 'code' => $c->code,
                'default_method' => $c->default_method, 'default_useful_life_years' => $c->default_useful_life_years,
                'default_salvage_pct' => (float) $c->default_salvage_pct, 'default_db_factor' => (float) $c->default_db_factor,
            ])->values(),
            'canManage' => (bool) $r->user()?->can('accounts.assets.manage'),
        ]);
    }

    public function store(Request $r)
    {
        $data = $r->validate([
            'name' => ['required', 'string', 'max:150'],
            'category_id' => ['required', 'integer', 'exists:acc_asset_categories,id'],
            'purchase_date' => ['required', 'date'],
            'in_service_date' => ['nullable', 'date', 'after_or_equal:purchase_date'],
            'cost' => ['required', 'numeric', 'min:0.01'],
            'salvage_value' => ['nullable', 'numeric', 'min:0'],
            'method' => ['nullable', 'in:straight_line,declining_balance'],
            'useful_life_years' => ['nullable', 'integer', 'min:1', 'max:100'],
            'db_factor' => ['nullable', 'numeric', 'min:0.01', 'max:10'],
            'supplier_partner_id' => ['nullable', 'integer', 'exists:partners,id'],
            'invoice_ref' => ['nullable', 'string', 'max:60'],
            'location' => ['nullable', 'string', 'max:160'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $asset = FixedAsset::create($data + [
            'asset_code' => $this->nextCode(),
            'status' => 'draft',
            'created_by' => $r->user()?->id,
        ]);

        return back()->with('success', 'Asset '.$asset->asset_code.' captured as draft.');
    }

    public function capitalize(Request $r, FixedAsset $asset)
    {
        $this->depreciation->capitalize($asset, $r->user()?->id);

        return back()->with('success', 'Asset '.$asset->asset_code.' capitalised — it will depreciate from the next run.');
    }

    public function dispose(Request $r, FixedAsset $asset)
    {
        $data = $r->validate([
            'disposed_date' => ['required', 'date'],
            'disposal_proceeds' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->depreciation->dispose($asset, $data['disposed_date'], isset($data['disposal_proceeds']) ? (float) $data['disposal_proceeds'] : null, $data['notes'] ?? null);

        return back()->with('success', 'Asset '.$asset->asset_code.' disposed. Post the write-off as a journal entry.');
    }

    /** FA-YYYY-#### continuing from the highest existing code for the year. */
    private function nextCode(): string
    {
        $year = now()->format('Y');
        $prefix = "FA-{$year}-";
        $last = FixedAsset::withTrashed()->where('asset_code', 'like', $prefix.'%')
            ->orderByDesc('asset_code')->value('asset_code');
        $seq = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
