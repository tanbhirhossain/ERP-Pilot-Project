<?php

namespace Modules\CORE\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\CORE\Models\Attachment;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Generic document attachments (Phase 2 — F19).
 *
 * Types are allow-listed in config('core.attachments.types'); each carries its
 * model and the view/manage permissions. Uploads land on the private local disk
 * and every download streams through the view-permission check — files are
 * never exposed under public/.
 */
class AttachmentController extends Controller
{
    public function store(Request $request, string $type, int $id): RedirectResponse
    {
        $spec = $this->spec($type);
        $record = $spec['model']::findOrFail($id);
        abort_unless($request->user()->can($spec['manage']), 403);

        $request->validate([
            'file' => 'required|file|max:'.config('core.attachments.max_kb').'|mimes:'.implode(',', config('core.attachments.mimes')),
        ]);

        $file = $request->file('file');
        $path = $file->store("attachments/{$type}/{$record->getKey()}", 'local');

        Attachment::create([
            'attachable_type' => $record::class,
            'attachable_id' => $record->getKey(),
            'uploaded_by' => $request->user()->id,
            'name' => $file->getClientOriginalName(),
            'path' => $path,
            'disk' => 'local',
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize() ?: 0,
        ]);

        return back()->with('success', 'Attachment uploaded.');
    }

    public function download(Request $request, Attachment $attachment): StreamedResponse
    {
        $spec = $this->specForClass($attachment->attachable_type);
        abort_unless($request->user()->can($spec['view']), 403);
        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404);

        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->name);
    }

    public function destroy(Request $request, Attachment $attachment): RedirectResponse
    {
        $spec = $this->specForClass($attachment->attachable_type);
        abort_unless($request->user()->can($spec['manage']), 403);

        Storage::disk($attachment->disk)->delete($attachment->path);
        $attachment->delete();

        return back()->with('success', 'Attachment removed.');
    }

    /**
     * @return array{model: class-string, view: string, manage: string}
     */
    private function spec(string $type): array
    {
        $spec = config("core.attachments.types.{$type}");
        abort_if($spec === null, 404, 'Unknown attachment type.');

        return $spec;
    }

    /**
     * @return array{model: class-string, view: string, manage: string}
     */
    private function specForClass(string $class): array
    {
        foreach (config('core.attachments.types', []) as $spec) {
            if ($spec['model'] === $class) {
                return $spec;
            }
        }

        abort(404, 'Unknown attachment record.');
    }
}
