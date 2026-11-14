<?php

namespace Modules\CORE\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\CORE\Services\DocumentPdfService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Streams a branded document PDF (Phase 2 — U16).
 *
 * GET core/documents/{type}/{id}/pdf — the type is allow-listed in the service
 * and gated on its own view permission, so a user can only download documents
 * they may already read in the UI.
 */
class DocumentPdfController extends Controller
{
    public function __invoke(Request $request, string $type, int $id, DocumentPdfService $documents): Response
    {
        $spec = $documents->spec($type);
        abort_if($spec === null, 404, 'Unknown document type.');
        abort_unless($request->user()->can($spec['permission']), 403);

        $doc = $spec['model']::with($spec['relations'])->findOrFail($id);

        return $documents->render($type, $doc, $request->user()->name)
            ->download($documents->filename($type, $doc));
    }
}
