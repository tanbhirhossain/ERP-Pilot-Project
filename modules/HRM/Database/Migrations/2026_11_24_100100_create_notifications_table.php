<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Standard Laravel database-notifications table.
 *
 * Powers the in-app notification bell (NotificationApiController). FCM push is
 * deliberately deferred (Phase 4); the events that will feed both channels get
 * wired in Phase 2 with the approval engine.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Older databases already carry this standard table (created outside the
        // migrator); only lay it on fresh installs so the migration never fails.
        if (Schema::hasTable('notifications')) {
            return;
        }

        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
