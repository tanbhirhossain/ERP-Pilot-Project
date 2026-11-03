<?php

namespace Modules\PARTNER\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CORE\Support\DataTableQuery;
use Modules\CORE\Support\PaginatedResource;
use Modules\PARTNER\Models\PartnerGroup;
use Modules\PARTNER\Requests\StorePartnerGroupRequest;
use Modules\PARTNER\Requests\UpdatePartnerGroupRequest;
use Modules\PARTNER\Services\PartnerGroupService;

class PartnerGroupController extends Controller
{
    public function __construct(private readonly PartnerGroupService $svc) {}

    public function index(Request $request): Response
    {
        $dt = DataTableQuery::fromRequest($request, ['name', 'code', 'type', 'sort_order', 'created_at'], ['type', 'parent_id', 'is_active']);
        return Inertia::render('PARTNER::Groups/Index', [
            'groups' => PaginatedResource::from($this->svc->paginate($dt)->through(fn (PartnerGroup $g) => [
                'id' => $g->id, 'name' => $g->name, 'code' => $g->code, 'type' => $g->type,
                'parent' => $g->parent ? ['id' => $g->parent->id, 'name' => $g->parent->name] : null,
                'description' => $g->description, 'sort_order' => $g->sort_order, 'is_active' => $g->is_active,
                'partners_count' => $g->partners_count, 'created_at' => $g->created_at?->toIso8601String(),
            ])),
            'tree' => $this->svc->tree(),
            'parentOptions' => $this->svc->options(null, 100),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('PARTNER::Groups/Create', [
            'parentOptions' => $this->svc->options(null, 100),
        ]);
    }

    public function store(StorePartnerGroupRequest $request): RedirectResponse
    {
        $this->svc->create($request->validated());
        return redirect()->route('partner.groups.index')->with('success', 'Partner group created.');
    }

    public function edit(PartnerGroup $group): Response
    {
        return Inertia::render('PARTNER::Groups/Edit', [
            'group' => $group->load(['parent']),
            'parentOptions' => $this->svc->options(null, 100),
        ]);
    }

    public function update(UpdatePartnerGroupRequest $request, PartnerGroup $group): RedirectResponse
    {
        $this->svc->update($group, $request->validated());
        return redirect()->route('partner.groups.index')->with('success', 'Partner group updated.');
    }

    public function destroy(PartnerGroup $group): RedirectResponse
    {
        try {
            $this->svc->delete($group);
            return redirect()->route('partner.groups.index')->with('success', 'Partner group deleted.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
