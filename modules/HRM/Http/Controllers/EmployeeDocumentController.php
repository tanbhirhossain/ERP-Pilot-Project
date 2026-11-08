<?php

namespace Modules\HRM\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Models\EmployeeDocument;
use Modules\HRM\Services\EmployeeDocumentService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Employee documents (NID, contracts, certificates, …).
 *
 * Files live on the `private` disk and are only ever served through
 * `download()`, which is behind `hrm.documents.view` — there is no public URL.
 * EmployeeDocumentService owns the upload rules (10 MB, an allow-list of
 * extensions) and signals violations with InvalidArgumentException, which is
 * turned into a field error rather than a 500.
 */
class EmployeeDocumentController extends Controller
{
    public function __construct(private readonly EmployeeDocumentService $svc) {}

    public function index(Request $r): Response
    {
        $q = EmployeeDocument::query()
            ->with(['employee:id,employee_id,full_name', 'verifier:id,name'])
            ->latest('id');

        if ($status = $r->input('verification_status')) {
            $q->where('verification_status', $status);
        }
        if ($type = $r->input('document_type')) {
            $q->where('document_type', $type);
        }
        if ($employeeId = $r->input('employee_id')) {
            $q->where('employee_id', $employeeId);
        }
        // "Expiring" is the actionable filter for HR: passports, trade licences
        // and contracts that are about to lapse.
        if ($r->boolean('expiring')) {
            $days = (int) $r->input('days', 30);
            $q->whereNotNull('expiry_date')
                ->whereDate('expiry_date', '<=', now()->addDays($days)->toDateString());
        }

        return Inertia::render('HRM::EmployeeDocuments/Index', [
            'documents' => $q->paginate(20)->withQueryString(),
            'stats' => $this->stats(),
            'types' => EmployeeDocument::query()->distinct()->orderBy('document_type')->pluck('document_type'),
            'filters' => $r->only(['verification_status', 'document_type', 'employee_id', 'expiring', 'days']),
        ]);
    }

    public function store(Request $r): RedirectResponse
    {
        $data = $r->validate([
            'employee_id' => ['required', 'string', 'exists:employees,employee_id'],
            'document_type' => ['required', 'string', 'max:100'],
            'title' => ['required', 'string', 'max:200'],
            'file' => ['required', 'file', 'max:10240'], // 10 MB, mirrors the service
            'issued_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date', 'after_or_equal:issued_date'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $document = $this->svc->store($data['employee_id'], $data, $r->file('file'));
        } catch (\InvalidArgumentException $e) {
            // The service rejects bad size/extension — surface it on the file
            // field instead of letting it become a 500.
            return back()->withErrors(['file' => $e->getMessage()])->withInput();
        }

        return back()->with('success', "\"{$document->title}\" uploaded for {$document->employee_id}. Awaiting verification.");
    }

    public function verify(Request $r, EmployeeDocument $document): RedirectResponse
    {
        $data = $r->validate([
            'verification_status' => ['required', 'in:Verified,Rejected,Expired'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->svc->verify($document, $data['verification_status'], $data['remarks'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['verification_status' => $e->getMessage()])->withInput();
        }

        return back()->with('success', "\"{$document->title}\" marked {$data['verification_status']}.");
    }

    /**
     * Stream a document from the private disk.
     *
     * Never redirect to a Storage::url() here — that would expose employee
     * documents on a publicly reachable path.
     */
    public function download(EmployeeDocument $document): StreamedResponse
    {
        abort_unless(
            $document->file_path && Storage::disk('private')->exists($document->file_path),
            404,
            'The stored file is missing from disk.'
        );

        return Storage::disk('private')->download(
            $document->file_path,
            $document->file_name ?: basename($document->file_path)
        );
    }

    public function destroy(EmployeeDocument $document): RedirectResponse
    {
        $title = $document->title;

        // Deletes the stored file and the row in one transaction.
        $this->svc->destroy($document);

        return back()->with('success', "\"{$title}\" deleted.");
    }

    public function forEmployee(Request $r, Employee $employee): Response
    {
        $documents = EmployeeDocument::query()
            ->with(['verifier:id,name'])
            ->where('employee_id', $employee->employee_id)
            ->latest('id');

        if ($status = $r->input('verification_status')) {
            $documents->where('verification_status', $status);
        }

        return Inertia::render('HRM::EmployeeDocuments/Index', [
            'documents' => $documents->paginate(20)->withQueryString(),
            'stats' => $this->stats($employee->employee_id),
            'employee' => ['employee_id' => $employee->employee_id, 'full_name' => $employee->full_name],
            'filters' => $r->only(['verification_status']),
        ]);
    }

    /**
     * @return array{total:int,pending:int,verified:int,rejected:int,expired:int,expiring_soon:int}
     */
    private function stats(?string $employeeId = null): array
    {
        $base = fn () => EmployeeDocument::query()
            ->when($employeeId, fn ($q, $id) => $q->where('employee_id', $id));

        $expiring = $employeeId === null
            ? $this->svc->expiringCounts()
            : [
                'expired' => $base()->whereNotNull('expiry_date')->whereDate('expiry_date', '<', now()->toDateString())->count(),
                'expiring_soon' => $base()->whereNotNull('expiry_date')
                    ->whereDate('expiry_date', '>=', now()->toDateString())
                    ->whereDate('expiry_date', '<=', now()->addDays(30)->toDateString())
                    ->count(),
            ];

        return [
            'total' => $base()->count(),
            'pending' => $base()->where('verification_status', 'Pending')->count(),
            'verified' => $base()->where('verification_status', 'Verified')->count(),
            'rejected' => $base()->where('verification_status', 'Rejected')->count(),
            'expired' => $expiring['expired'],
            'expiring_soon' => $expiring['expiring_soon'],
        ];
    }
}
