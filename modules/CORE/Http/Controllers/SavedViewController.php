<?php

namespace Modules\CORE\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\CORE\Models\SavedView;

/**
 * Per-user saved table views (Phase 2 — U8).
 *
 * Every query is scoped to the signed-in user — there is no route parameter that
 * could read or delete another person's views. The state blob is opaque to the
 * server (query params + hidden columns); the DataTable interprets it.
 */
class SavedViewController extends Controller
{
    public function index(Request $request, string $tableKey): JsonResponse
    {
        $views = SavedView::where('user_id', $request->user()->id)
            ->where('table_key', $tableKey)
            ->orderBy('name')
            ->get(['id', 'name', 'state']);

        return response()->json(['views' => $views]);
    }

    public function store(Request $request, string $tableKey): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:80',
            'state' => 'required|array',
            'state.query' => 'nullable|array',
            'state.hidden' => 'nullable|array',
            'state.hidden.*' => 'string',
        ]);

        $view = SavedView::updateOrCreate(
            ['user_id' => $request->user()->id, 'table_key' => $tableKey, 'name' => $data['name']],
            ['state' => $data['state']],
        );

        return response()->json(['view' => $view->only(['id', 'name', 'state'])]);
    }

    public function destroy(Request $request, string $tableKey, int $id): JsonResponse
    {
        $view = SavedView::where('user_id', $request->user()->id)
            ->where('table_key', $tableKey)
            ->findOrFail($id);
        $view->delete();

        return response()->json(['ok' => true]);
    }
}
