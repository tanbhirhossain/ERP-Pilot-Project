<?php

namespace Modules\ACCOUNTS\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\ACCOUNTS\Models\Journal;
use Modules\ACCOUNTS\Requests\StoreJournalRequest;
use Modules\ACCOUNTS\Services\AccountService;
use Modules\ACCOUNTS\Services\JournalService;
use Modules\CORE\Support\DataTableQuery;
use Modules\CORE\Support\PaginatedResource;

class JournalController extends Controller
{
    public function __construct(private readonly JournalService $svc, private readonly AccountService $accounts) {}

    public function index(Request $r): Response
    {
        $dt = DataTableQuery::fromRequest($r, ['journal_no', 'journal_date', 'created_at'], ['type', 'status', 'fiscal_year_id', 'date_from', 'date_to']);

        return Inertia::render('ACCOUNTS::Journals/Index', [
            'journals' => PaginatedResource::from($this->svc->paginate($dt)->through(fn (Journal $j) => [
                'id' => $j->id, 'journal_no' => $j->journal_no, 'reference_no' => $j->reference_no, 'journal_date' => $j->journal_date->format('Y-m-d'),
                'type' => $j->type, 'narration' => $j->narration, 'total_debit' => $j->total_debit, 'total_credit' => $j->total_credit,
                'status' => $j->status, 'lines_count' => $j->lines_count, 'created_at' => $j->created_at?->toIso8601String(),
            ])),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('ACCOUNTS::Journals/Create', [
            'accountOptions' => $this->accounts->options(null, 100),
        ]);
    }

    public function store(StoreJournalRequest $r): RedirectResponse
    {
        try {
            $j = $this->svc->create($r->validated() + ['total_debit' => array_sum(array_map(fn ($l) => (float) ($l['debit'] ?? 0), $r->input('lines'))), 'total_credit' => array_sum(array_map(fn ($l) => (float) ($l['credit'] ?? 0), $r->input('lines')))]);

            return redirect()->route('accounts.journals.show', $j->id)->with('success', "Journal {$j->journal_no} created.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    public function show(Journal $journal): Response
    {
        $journal->load(['lines.account', 'fiscalYear', 'fiscalPeriod', 'ledgerEntries']);

        return Inertia::render('ACCOUNTS::Journals/Show', ['journal' => $journal]);
    }

    public function edit(Journal $journal): Response
    {
        $journal->load('lines');

        return Inertia::render('ACCOUNTS::Journals/Edit', ['journal' => $journal, 'accountOptions' => $this->accounts->options(null, 100)]);
    }

    public function update(StoreJournalRequest $r, Journal $journal): RedirectResponse
    {
        try {
            $this->svc->update($journal, $r->validated() + ['total_debit' => array_sum(array_map(fn ($l) => (float) ($l['debit'] ?? 0), $r->input('lines'))), 'total_credit' => array_sum(array_map(fn ($l) => (float) ($l['credit'] ?? 0), $r->input('lines')))]);

            return redirect()->route('accounts.journals.show', $journal->id)->with('success', 'Journal updated.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function destroy(Journal $journal): RedirectResponse
    {
        try {
            $this->svc->delete($journal);

            return redirect()->route('accounts.journals.index')->with('success', 'Journal deleted.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function post(Journal $journal): RedirectResponse
    {
        try {
            $this->svc->post($journal);

            return back()->with('success', "Journal {$journal->journal_no} posted.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function void(Request $r, Journal $journal): RedirectResponse
    {
        $r->validate(['reason' => 'required|string|max:500']);
        try {
            $this->svc->void($journal, $r->input('reason'));

            return back()->with('success', "Journal {$journal->journal_no} voided.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
