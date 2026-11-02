<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spatie Activity Log table (JCI audit trail storage) with the extra indexes
 * an audit viewer needs when the table grows to hundreds of millions of rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_log', function (Blueprint $table): void {
            $table->id();
            $table->string('log_name')->nullable()->index();
            $table->text('description');
            $table->nullableMorphs('subject', 'subject');
            $table->string('event')->nullable();
            $table->nullableMorphs('causer', 'causer');
            $table->json('attribute_changes')->nullable();
            $table->json('properties')->nullable();
            $table->uuid('batch_uuid')->nullable()->index();
            $table->timestamps();

            // Audit viewer hot paths
            $table->index(['log_name', 'created_at'], 'activity_log_log_name_created_at_index');
            $table->index('created_at', 'activity_log_created_at_index');
            $table->index('event', 'activity_log_event_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};
