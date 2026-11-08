<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roster_overtimes', function (Blueprint $table): void {
            $table->id();
            $table->string('employee_id');
            $table->date('overtime_date');
            $table->enum('overtime_type', ['Overtime', 'Extra Duty', 'Holiday Duty'])->default('Overtime');
            $table->foreignId('shift_id')->nullable()->constrained('roster_shifts')->nullOnDelete();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->decimal('hours', 5, 2)->default(0);
            $table->text('reason')->nullable();
            $table->enum('status', ['Pending', 'Approved', 'Rejected', 'Cancelled'])->default('Pending');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->foreign('employee_id')->references('employee_id')->on('employees')->onDelete('cascade');
            $table->index(['employee_id', 'overtime_date']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roster_overtimes');
    }
};
