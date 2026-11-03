<?php

namespace Modules\ACCOUNTS\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\ACCOUNTS\Models\Account;
use Modules\ACCOUNTS\Requests\StoreAccountRequest;
use Modules\ACCOUNTS\Services\AccountService;
use Modules\CORE\Support\DataTableQuery;
use Modules\CORE\Support\PaginatedResource;

class AccountController extends Controller
{
    public function __construct(private readonly AccountService $svc) {}

    public function index(Request $r): Response
    {
        $dt = DataTableQuery::fromRequest($r, ['code', 'name', 'created_at'], ['account_type', 'nature', 'is_group', 'is_active', 'parent_id']);
        return Inertia::render('ACCOUNTS::Coa/Index', [
            'accounts' => PaginatedResource::from($this->svc->paginate($dt)->through(fn (Account $a) => [
                'id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'account_type' => $a->account_type, 'nature' => $a->nature,
                'parent' => $a->parent ? ['id' => $a->parent->id, 'name' => $a->parent->name, 'code' => $a->parent->code] : null,
                'level' => $a->level, 'is_group' => $a->is_group, 'is_system' => $a->is_system, 'is_active' => $a->is_active,
                'children_count' => $a->children_count, 'lines_count' => $a->journal_lines_count, 'created_at' => $a->created_at?->toIso8601String(),
            ])),
            'tree' => $this->svc->tree()->map(fn($a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'children' => $a->children->map(fn($c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name])]),
            'parentOptions' => Account::where('is_group', true)->active()->orderBy('code')->limit(100)->get(['id', 'code', 'name'])->map(fn($a) => ['value' => $a->id, 'label' => "{$a->code} — {$a->name}"]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('ACCOUNTS::Coa/Create', [
            'parentOptions' => Account::where('is_group', true)->active()->orderBy('code')->get(['id', 'code', 'name'])->map(fn($a) => ['value' => $a->id, 'label' => "{$a->code} — {$a->name}"]),
        ]);
    }

    public function store(StoreAccountRequest $r): RedirectResponse
    {
        $a = $this->svc->create($r->validated());
        return redirect()->route('accounts.coa.show', $a->id)->with('success', "Account {$a->code} created.");
    }

    public function show(Account $account): Response
    {
        $account->load(['parent', 'children', 'ledgerEntries' => fn($q) => $q->latest()->limit(20)]);
        return Inertia::render('ACCOUNTS::Coa/Show', [
            'account' => $account,
            'balance' => app(\Modules\ACCOUNTS\Services\LedgerService::class)->accountBalance($account->id),
            'ancestors' => $this->svc->ancestors($account),
        ]);
    }

    public function edit(Account $account): Response
    {
        return Inertia::render('ACCOUNTS::Coa/Edit', [
            'account' => $account,
            'parentOptions' => Account::where('is_group', true)->where('id', '!=', $account->id)->active()->orderBy('code')->get(['id', 'code', 'name'])->map(fn($a) => ['value' => $a->id, 'label' => "{$a->code} — {$a->name}"]),
        ]);
    }

    public function update(StoreAccountRequest $r, Account $account): RedirectResponse
    {
        $this->svc->update($account, $r->validated());
        return redirect()->route('accounts.coa.show', $account->id)->with('success', 'Account updated.');
    }

    public function destroy(Account $account): RedirectResponse
    {
        try { $this->svc->delete($account); return redirect()->route('accounts.coa.index')->with('success', 'Account deleted.'); }
        catch (\DomainException $e) { return back()->with('error', $e->getMessage()); }
    }
}
