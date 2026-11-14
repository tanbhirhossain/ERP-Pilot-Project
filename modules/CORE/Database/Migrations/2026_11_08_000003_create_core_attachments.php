<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generic document attachments (Phase 2 — F19).
 *
 * Scanned vendor invoices, contracts and supporting documents hang off any
 * allow-listed record (invoice, bill, purchase order, journal) via a polymorphic
 * pair. Files live on the private local disk and are streamed through an
 * authorised controller — never served directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_attachments', function (Blueprint $table): void {
            $table->id();
            $table->string('attachable_type');
            $table->unsignedBigInteger('attachable_id');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('path');
            $table->string('disk', 40)->default('local');
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->timestamps();

            $table->index(['attachable_type', 'attachable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_attachments');
    }
};
