<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user saved table views (Phase 2 — U8).
 *
 * A view captures a table's query state (search / filters / sort) plus its column
 * visibility, so an operator can return to "Overdue invoices, compact, no notes
 * column" in one click from any device. Scoped to the user who saved it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_saved_views', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('table_key');
            $table->string('name', 80);
            $table->json('state');
            $table->timestamps();

            $table->unique(['user_id', 'table_key', 'name']);
            $table->index(['user_id', 'table_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_saved_views');
    }
};
