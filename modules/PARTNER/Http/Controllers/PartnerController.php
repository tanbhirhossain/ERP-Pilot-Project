<?php

namespace Modules\PARTNER\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CORE\Models\User;
use Modules\CORE\Support\DataTableQuery;
use Modules\CORE\Support\PaginatedResource;
use Modules\PARTNER\Actions\VerifyPartnerAction;
use Modules\PARTNER\Models\Partner;
use Modules\PARTNER\Requests\StorePartnerRequest;
use Modules\PARTNER\Requests\UpdatePartnerRequest;
use Modules\PARTNER\Services\PartnerGroupService;
use Modules\PARTNER\Services\PartnerService;
use Modules\PARTNER\Services\PriceListService;

class PartnerController extends Controller
{
    public function __construct(
        private readonly PartnerService $partners,
        private readonly PartnerGroupService $groups,
        private readonly PriceListService $priceLists,
    ) {}

    /** Generic index — used by both vendors & customers via type filter */
    public function index(Request $request, ?string $type = null): Response
    {
        $allowedType = in_array($type, ['vendor', 'customer', 'both'], true) ? $type : null;

        $dt = DataTableQuery::fromRequest($request, ['partner_code', 'name', 'type', 'phone', 'credit_limit', 'created_at', 'is_verified'], ['type', 'group_id', 'is_active', 'is_verified', 'verification_status']);
        $partners = $this->partners->paginate($dt, $allowedType);

        $view = match ($allowedType) {
            'vendor' => 'PARTNER::Vendors/Index',
            'customer' => 'PARTNER::Customers/Index',
            default => 'PARTNER::Vendors/Index',
        };

        return Inertia::render($view, [
            'partners' => PaginatedResource::from($partners->through(fn (Partner $p) => $this->toRow($p))),
            'groupOptions' => $this->groups->options(null, 100, $allowedType),
            'type' => $allowedType,
        ]);
    }

    public function create(Request $request, ?string $type = null): Response
    {
        $defaultType = in_array($type, ['vendor', 'customer', 'both'], true) ? $type : 'vendor';
        $view = $defaultType === 'customer' ? 'PARTNER::Customers/Create' : 'PARTNER::Vendors/Create';

        return Inertia::render($view, [
            'groupOptions' => $this->groups->options(null, 100, $defaultType),
            'priceListOptions' => $this->priceLists->options(null, 100),
            'userOptions' => User::orderBy('name')->limit(100)->get()->map(fn ($u) => ['value' => $u->id, 'label' => $u->name, 'description' => $u->email])->values()->all(),
            'defaultType' => $defaultType,
        ]);
    }

    public function store(StorePartnerRequest $request): RedirectResponse
    {
        $partner = $this->partners->create($request->validated());

        $route = $partner->type === 'customer' ? 'partner.customers.index' : 'partner.vendors.index';
        if ($partner->type === 'both') {
            $route = 'partner.vendors.index';
        }

        return redirect()->route($route)->with('success', "Partner {$partner->partner_code} created successfully.");
    }

    public function show(Partner $partner): Response
    {
        $partner->load(['group', 'priceList', 'contacts', 'addresses', 'banks', 'documents', 'notes.author', 'creator', 'verifier', 'assignee', 'activities.user']);

        return Inertia::render('PARTNER::Vendors/Show', [
            'partner' => $this->toDetail($partner),
            'creditCheck' => $this->partners->creditCheck($partner)?->toArray(),
        ]);
    }

    public function edit(Partner $partner): Response
    {
        $partner->load(['contacts', 'addresses', 'banks']);
        $view = $partner->type === 'customer' ? 'PARTNER::Customers/Edit' : 'PARTNER::Vendors/Edit';

        return Inertia::render($view, [
            'partner' => $this->toEdit($partner),
            'groupOptions' => $this->groups->options(null, 100, $partner->type),
            'priceListOptions' => $this->priceLists->options(null, 100),
            'userOptions' => User::orderBy('name')->limit(100)->get()->map(fn ($u) => ['value' => $u->id, 'label' => $u->name, 'description' => $u->email])->values()->all(),
        ]);
    }

    public function update(UpdatePartnerRequest $request, Partner $partner): RedirectResponse
    {
        $this->partners->update($partner, $request->validated());
        $route = $partner->type === 'customer' ? 'partner.customers.index' : 'partner.vendors.index';

        return redirect()->route($route)->with('success', "Partner {$partner->partner_code} updated.");
    }

    public function destroy(Partner $partner): RedirectResponse
    {
        $code = $partner->partner_code;
        $type = $partner->type;
        $this->partners->delete($partner);
        $route = $type === 'customer' ? 'partner.customers.index' : 'partner.vendors.index';

        return redirect()->route($route)->with('success', "Partner {$code} deleted.");
    }

    public function toggleActive(Partner $partner): RedirectResponse
    {
        $this->partners->toggleActive($partner);

        return back()->with('success', "Partner {$partner->partner_code} status toggled.");
    }

    public function verify(Request $request, Partner $partner): RedirectResponse
    {
        $request->validate([
            'action' => ['required', 'in:approve,reject'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $approve = $request->input('action') === 'approve';
        app(VerifyPartnerAction::class)->execute($partner, $approve, $request->input('remarks'));

        return back()->with('success', $approve ? "Partner {$partner->partner_code} approved." : "Partner {$partner->partner_code} rejected.");
    }

    public function addNote(Request $request, Partner $partner): RedirectResponse
    {
        $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'content' => ['required', 'string', 'max:5000'],
            'is_private' => ['nullable', 'boolean'],
            'is_pinned' => ['nullable', 'boolean'],
        ]);

        $this->partners->addNote($partner, $request->only(['title', 'content', 'is_private', 'is_pinned']));

        return back()->with('success', 'Note added.');
    }

    public function addDocument(Request $request, Partner $partner): RedirectResponse
    {
        $request->validate([
            'type' => ['required', 'in:nid,trade_license,tin,vat,bin,agreement,cheque,other'],
            'title' => ['required', 'string', 'max:255'],
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,doc,docx'],
            'expiry_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->partners->addDocument($partner, $request->only(['type', 'title', 'file', 'expiry_date', 'notes']));

        return back()->with('success', 'Document uploaded.');
    }

    public function creditCheck(Request $request, Partner $partner)
    {
        $amount = (float) $request->input('amount', 0);
        $result = $this->partners->creditCheck($partner, $amount);

        return response()->json($result?->toArray());
    }

    // ── Mappers ──

    private function toRow(Partner $p): array
    {
        return [
            'id' => $p->id,
            'partner_code' => $p->partner_code,
            'name' => $p->name,
            'legal_name' => $p->legal_name,
            'type' => $p->type,
            'type_label' => ucfirst($p->type),
            'group' => $p->group ? ['id' => $p->group->id, 'name' => $p->group->name] : null,
            'phone' => $p->phone,
            'email' => $p->email,
            'credit_limit' => $p->credit_limit,
            'current_balance' => $p->current_balance,
            'available_credit' => (float) $p->credit_limit - (float) $p->current_balance,
            'is_active' => $p->is_active,
            'is_verified' => $p->is_verified,
            'verification_status' => $p->verification_status,
            'created_at' => $p->created_at?->toIso8601String(),
        ];
    }

    private function toDetail(Partner $p): array
    {
        return [
            'id' => $p->id,
            'partner_code' => $p->partner_code,
            'type' => $p->type,
            'name' => $p->name,
            'legal_name' => $p->legal_name,
            'group' => $p->group,
            'price_list' => $p->priceList,
            'trade_license' => $p->trade_license,
            'tin' => $p->tin,
            'bin' => $p->bin,
            'vat_reg_no' => $p->vat_reg_no,
            'nid' => $p->nid,
            'phone' => $p->phone,
            'email' => $p->email,
            'website' => $p->website,
            'credit_limit' => $p->credit_limit,
            'payment_terms_days' => $p->payment_terms_days,
            'payment_terms' => $p->payment_terms,
            'opening_balance' => $p->opening_balance,
            'current_balance' => $p->current_balance,
            'currency' => $p->currency,
            'is_active' => $p->is_active,
            'is_verified' => $p->is_verified,
            'verification_status' => $p->verification_status,
            'verified_at' => $p->verified_at?->toIso8601String(),
            'verifier' => $p->verifier ? ['id' => $p->verifier->id, 'name' => $p->verifier->name] : null,
            'assigned_to' => $p->assignee ? ['id' => $p->assignee->id, 'name' => $p->assignee->name] : null,
            'notes' => $p->notes,
            'internal_notes' => $p->internal_notes,
            'contacts' => $p->contacts,
            'addresses' => $p->addresses,
            'banks' => $p->banks,
            'documents' => $p->documents,
            'partner_notes' => $p->notes, // alias
            'activities' => $p->activities->map(fn ($a) => [
                'id' => $a->id, 'action' => $a->action, 'description' => $a->description, 'properties' => $a->properties,
                'user' => $a->user ? ['name' => $a->user->name] : null, 'created_at' => $a->created_at?->toIso8601String(),
            ]),
            'creator' => $p->creator ? ['name' => $p->creator->name] : null,
            'created_at' => $p->created_at?->toIso8601String(),
            'updated_at' => $p->updated_at?->toIso8601String(),
        ];
    }

    private function toEdit(Partner $p): array
    {
        return [
            'id' => $p->id,
            'partner_code' => $p->partner_code,
            'type' => $p->type,
            'name' => $p->name,
            'legal_name' => $p->legal_name,
            'group_id' => $p->group_id,
            'price_list_id' => $p->price_list_id,
            'trade_license' => $p->trade_license,
            'tin' => $p->tin,
            'bin' => $p->bin,
            'vat_reg_no' => $p->vat_reg_no,
            'nid' => $p->nid,
            'phone' => $p->phone,
            'email' => $p->email,
            'website' => $p->website,
            'credit_limit' => $p->credit_limit,
            'payment_terms_days' => $p->payment_terms_days,
            'payment_terms' => $p->payment_terms,
            'opening_balance' => $p->opening_balance,
            'currency' => $p->currency,
            'is_active' => $p->is_active,
            'assigned_to' => $p->assigned_to,
            'notes' => $p->notes,
            'internal_notes' => $p->internal_notes,
            'contacts' => $p->contacts->toArray(),
            'addresses' => $p->addresses->toArray(),
            'banks' => $p->banks->toArray(),
        ];
    }
}
