<?php

namespace Modules\ACCOUNTS\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\ACCOUNTS\Models\Bank;
use Modules\ACCOUNTS\Requests\StoreBankRequest;
use Modules\ACCOUNTS\Services\AccountService;
use Modules\ACCOUNTS\Services\BankService;
use Modules\CORE\Support\DataTableQuery;
use Modules\CORE\Support\PaginatedResource;

class BankController extends Controller
{
    public function __construct(private readonly BankService $svc, private readonly AccountService $accounts) {}

    public function index(Request $r): Response
    {
        $dt = DataTableQuery::fromRequest($r, ['name', 'created_at'], ['is_active']);

        return Inertia::render('ACCOUNTS::Banks/Index', [
            'banks' => PaginatedResource::from($this->svc->paginate($dt)->through(fn (Bank $b) => [
                'id' => $b->id, 'name' => $b->name, 'code' => $b->code, 'bank_name' => $b->bank_name, 'account_no' => $b->account_no,
                'account' => $b->account ? ['code' => $b->account->code, 'name' => $b->account->name] : null,
                'current_balance' => $b->current_balance, 'is_active' => $b->is_active, 'created_at' => $b->created_at?->toIso8601String(),
            ])),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('ACCOUNTS::Banks/Create', ['accountOptions' => $this->accounts->options(null, 100)]);
    }

    public function store(StoreBankRequest $r): RedirectResponse
    {
        $b = $this->svc->create($r->validated());

        return redirect()->route('accounts.banks.show', $b->id)->with('success', "Bank {$b->name} created.");
    }

    public function show(Bank $bank): Response
    {
        $bank->load(['account', 'transactions']);

        return Inertia::render('ACCOUNTS::Banks/Show', ['bank' => $bank]);
    }

    public function edit(Bank $bank): Response
    {
        return Inertia::render('ACCOUNTS::Banks/Edit', ['bank' => $bank, 'accountOptions' => $this->accounts->options(null, 100)]);
    }

    public function update(StoreBankRequest $r, Bank $bank): RedirectResponse
    {
        $this->svc->update($bank, $r->validated());

        return redirect()->route('accounts.banks.show', $bank->id)->with('success', 'Bank updated.');
    }

    public function destroy(Bank $bank): RedirectResponse
    {
        try {
            $this->svc->delete($bank);

            return redirect()->route('accounts.banks.index')->with('success', 'Bank deleted.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
