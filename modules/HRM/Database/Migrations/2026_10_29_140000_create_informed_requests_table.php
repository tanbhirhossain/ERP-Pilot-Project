<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('informed_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('employee_id');
            $table->date('informed_date');
            $table->enum('informed_type', ['Late Coming','Early Out','Absent Informed','Field Work','On Duty','Other'])->default('Late Coming');
            $table->time('expected_time')->nullable()->comment('Expected in/out time if Late/Early');
            $table->text('reason')->nullable();
            $table->enum('status', ['Pending','Acknowledged','Rejected','Cancelled'])->default('Pending');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->foreign('employee_id')->references('employee_id')->on('employees')->onDelete('cascade');
            $table->index(['employee_id','informed_date']);
            $table->index('status');
        });
    }
    public function down(): void { Schema::dropIfExists('informed_requests'); }
};
