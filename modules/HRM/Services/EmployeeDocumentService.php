<?php

namespace Modules\HRM\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\HRM\Models\EmployeeDocument;

class EmployeeDocumentService
{
    public function store(string $employeeId, array $data, ?UploadedFile $file): EmployeeDocument
    {
        return DB::transaction(function () use ($employeeId, $data, $file) {
            if (!$file || !$file->isValid()) throw new \InvalidArgumentException('Valid file is required.');
            $this->assertFile($file);
            $path = $file->store("hrm-documents/{$employeeId}", 'private');
            return EmployeeDocument::create([
                'employee_id' => $employeeId,
                'document_type' => $data['document_type'],
                'title' => $data['title'],
                'file_path' => $path,
                'file_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
                'issued_date' => $data['issued_date'] ?? null,
                'expiry_date' => $data['expiry_date'] ?? null,
                'verification_status' => 'Pending',
                'remarks' => $data['remarks'] ?? null,
                'created_by' => auth()->id(),
            ]);
        });
    }

    public function verify(EmployeeDocument $doc, string $status, ?string $remarks = null): EmployeeDocument
    {
        if (!in_array($status, ['Verified','Rejected','Expired'], true)) throw new \InvalidArgumentException('Invalid status.');
        $doc->update([
            'verification_status' => $status,
            'verified_by' => auth()->id(),
            'verified_at' => now(),
            'remarks' => $remarks ?? $doc->remarks,
        ]);
        return $doc->fresh();
    }

    public function destroy(EmployeeDocument $doc): void
    {
        DB::transaction(function () use ($doc) {
            if ($doc->file_path && Storage::disk('private')->exists($doc->file_path)) Storage::disk('private')->delete($doc->file_path);
            $doc->delete();
        });
    }

    public function expiringCounts(int $days = 30): array
    {
        return [
            'expired' => EmployeeDocument::whereNotNull('expiry_date')->whereDate('expiry_date','<',now()->toDateString())->count(),
            'expiring_soon' => EmployeeDocument::whereNotNull('expiry_date')->whereBetween('expiry_date',[now()->toDateString(), now()->addDays($days)->toDateString()])->count(),
        ];
    }

    private function assertFile(UploadedFile $file): void
    {
        $max = 10 * 1024 * 1024; // 10 MB
        if ($file->getSize() > $max) throw new \InvalidArgumentException('File exceeds 10 MB limit.');
        $allowed = ['pdf','jpg','jpeg','png','webp','doc','docx'];
        $ext = strtolower($file->getClientOriginalExtension());
        if (!in_array($ext, $allowed, true)) throw new \InvalidArgumentException("File type .{$ext} not allowed. Use: ".implode(', ',$allowed));
    }
}
