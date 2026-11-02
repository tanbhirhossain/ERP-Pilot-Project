<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('leave_type_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('leave_type_id')->unique()->constrained('leave_types')->cascadeOnDelete();
            $table->enum('day_count_mode',['calendar_days','working_days'])->default('calendar_days');
            $table->enum('accrual_mode',['annual','monthly'])->default('annual');
            $table->enum('proration_mode',['none','monthly','daily'])->default('none');
            $table->json('working_days')->nullable(); // Carbon weekday numbers: Sunday=0 through Saturday=6
            $table->json('approval_steps')->nullable(); // ordered stage labels, e.g. ["HR"]
            $table->timestamps();
        });
        Schema::create('leave_approval_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('leave_request_id')->constrained('leave_requests')->cascadeOnDelete();
            $table->unsignedSmallInteger('stage_index');
            $table->string('stage_name',100);
            $table->enum('decision',['Approved','Rejected']);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('comments')->nullable();
            $table->timestamp('decided_at');
            $table->timestamps();
            $table->index(['leave_request_id','stage_index']);
        });
        Schema::create('leave_request_workflows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('leave_request_id')->unique()->constrained('leave_requests')->cascadeOnDelete();
            $table->json('steps');
            $table->unsignedSmallInteger('current_stage')->default(0);
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('leave_request_workflows'); Schema::dropIfExists('leave_approval_actions'); Schema::dropIfExists('leave_type_settings'); }
};
