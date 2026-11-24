<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dynamic approval chains (Phase 2: approval engine).
 *
 * - approval_chains + approval_chain_steps: the editable chains. Key 'leave'
 *   drives leave requests (5 stages), key 'overtime' drives OT/Extra-Duty
 *   records (4 stages). Edited through the chain admin API; each request
 *   snapshots the labels at creation, so editing a chain only affects NEW
 *   requests — in-flight ones keep walking the chain they started on.
 * - overtime_request_workflows + overtime_approval_actions: the staged OT/ED
 *   flow, mirroring leave_request_workflows + leave_approval_actions column
 *   for column so the engine treats both flows uniformly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_chains', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('approval_chain_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('approval_chain_id')->constrained('approval_chains')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('stage_key', 40);
            $table->string('stage_label', 100);
            $table->enum('resolver', ['supervisor', 'role']);
            $table->string('role_name', 100)->nullable();
            $table->timestamps();

            $table->unique(['approval_chain_id', 'position']);
        });

        Schema::create('overtime_request_workflows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('overtime_id')->unique()->constrained('roster_overtimes')->cascadeOnDelete();
            $table->json('steps');
            $table->unsignedSmallInteger('current_stage')->default(0);
            $table->timestamps();
        });

        Schema::create('overtime_approval_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('overtime_id')->constrained('roster_overtimes')->cascadeOnDelete();
            $table->unsignedSmallInteger('stage_index');
            $table->string('stage_name', 100);
            $table->enum('decision', ['Approved', 'Rejected']);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('comments')->nullable();
            $table->timestamp('decided_at');
            $table->timestamps();
            $table->index(['overtime_id', 'stage_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overtime_approval_actions');
        Schema::dropIfExists('overtime_request_workflows');
        Schema::dropIfExists('approval_chain_steps');
        Schema::dropIfExists('approval_chains');
    }
};
