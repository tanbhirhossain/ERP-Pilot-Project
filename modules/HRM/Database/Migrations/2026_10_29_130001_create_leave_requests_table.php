<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('leave_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('employee_id');
            $table->foreignId('leave_type_id')->nullable()->constrained('leave_types')->nullOnDelete();
            $table->date('from_date');
            $table->date('to_date');
            $table->decimal('days', 5, 2)->default(1);
            $table->boolean('is_half_day')->default(false);
            $table->text('reason')->nullable();
            $table->enum('status', ['Pending','Approved','Rejected','Cancelled'])->default('Pending');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->foreign('employee_id')->references('employee_id')->on('employees')->onDelete('cascade');
            $table->index(['employee_id','status']);
            $table->index('from_date');
        });
    }
    public function down(): void { Schema::dropIfExists('leave_requests'); } // leave_types dropped by 130000
};
