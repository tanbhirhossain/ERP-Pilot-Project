<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('roster_shift_swaps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('roster_duty_id')->constrained('roster_duties')->onDelete('cascade');
            $table->string('requester_employee_id');
            $table->string('target_employee_id')->nullable();
            $table->foreignId('requested_shift_id')->nullable()->constrained('roster_shifts')->nullOnDelete();
            $table->foreignId('target_shift_id')->nullable()->constrained('roster_shifts')->nullOnDelete();
            $table->date('swap_date');
            $table->text('reason')->nullable();
            $table->enum('status', ['Pending','Approved','Rejected','Cancelled'])->default('Pending');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->foreign('requester_employee_id')->references('employee_id')->on('employees')->onDelete('cascade');
        });
    }
    public function down(): void { Schema::dropIfExists('roster_shift_swaps'); }
};
