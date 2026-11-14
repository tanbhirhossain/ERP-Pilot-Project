<?php

namespace Modules\CORE\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Modules\CORE\Exports\ImportTemplateExport;
use Modules\CORE\Services\ImportService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Bulk import UI (Phase 1 — F5).
 *
 * Two-step: upload → preview (validate, write nothing) → commit. The uploaded
 * file is stored temporarily and its path carried in the session between the
 * two steps, so the operator reviews exactly the rows that will be written.
 * Permission is checked per import type, both on preview and on commit.
 */
class ImportController extends Controller
{
    public function __construct(private readonly ImportService $imports) {}

    public function index(Request $request): Response
    {
        $types = [];
        foreach ($this->imports->all() as $key => $importer) {
            if ($request->user()->can($importer->permission())) {
                $types[] = [
                    'key' => $key,
                    'label' => $importer->label(),
                    'headers' => $importer->headers(),
                ];
            }
        }

        return Inertia::render('CORE::Imports/Index', ['types' => $types]);
    }

    public function template(string $type): BinaryFileResponse
    {
        $importer = $this->imports->get($type);
        abort_unless(request()->user()?->can($importer->permission()), 403);

        $export = new ImportTemplateExport($importer->headers(), $importer->exampleRow());

        return Excel::download($export, "{$type}-template.xlsx");
    }

    public function preview(Request $request, string $type): RedirectResponse
    {
        $importer = $this->imports->get($type);
        abort_unless($request->user()->can($importer->permission()), 403);

        $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv,txt|max:10240']);

        $path = $request->file('file')->store('imports');

        $result = $this->imports->preview($type, storage_path('app/'.$path));

        // Carry the stored path + result to the commit step / UI.
        $request->session()->put("import.{$type}", [
            'path' => storage_path('app/'.$path),
            'result' => $result,
        ]);

        return back()->with('preview', $result + ['type' => $type]);
    }

    public function commit(Request $request, string $type): RedirectResponse
    {
        $importer = $this->imports->get($type);
        abort_unless($request->user()->can($importer->permission()), 403);

        $state = $request->session()->get("import.{$type}");

        if (! $state || ! isset($state['path']) || ! file_exists($state['path'])) {
            return back()->with('error', 'Upload a file and preview it before committing.');
        }

        try {
            $result = $this->imports->commit($type, $state['path']);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        // Clean up the temp file + session state.
        @unlink($state['path']);
        $request->session()->forget("import.{$type}");

        return back()->with('success', "Imported {$result['imported']} record(s) from {$result['rows']} row(s).");
    }
}
