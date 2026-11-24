<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — push device tokens (FCM HTTP v1).
 *
 * One row per app installation. `token` is globally unique: registering a
 * token that another user holds transfers it, which is what a re-login on a
 * shared device (or a second-hand phone) must do. Dead tokens (FCM answers
 * NOT_FOUND/UNREGISTERED) are pruned by PushService, never left to rot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_device_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token', 2048)->unique();
            $table->enum('platform', ['android', 'ios', 'web'])->default('android');
            $table->string('device_name', 120)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_device_tokens');
    }
};
