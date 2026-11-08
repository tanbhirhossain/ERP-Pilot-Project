<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_dailies', function (Blueprint $table): void {
            $table->id();
            $table->string('employee_id');
            $table->date('duty_date');
            $table->foreignId('primary_shift_id')->nullable()->constrained('roster_shifts')->nullOnDelete();
            $table->time('expected_in_time')->nullable()->comment('snapshot in');
            $table->time('expected_out_time')->nullable()->comment('snapshot out');
            $table->dateTime('primary_punch_in')->nullable();
            $table->dateTime('primary_punch_out')->nullable();
            $table->decimal('primary_worked_hours', 5, 2)->default(0);
            $table->decimal('total_overtime_hours', 5, 2)->default(0);
            $table->decimal('grand_total_hours', 5, 2)->default(0);
            $table->enum('status', ['Present', 'Late', 'Early Out', 'Absent', 'On Leave', 'Holiday', 'Missing In', 'Missing Out', 'Extra Duty', 'Late & Early Out'])->default('Present');
            $table->text('remarks')->nullable();
            $table->foreign('employee_id')->references('employee_id')->on('employees')->onDelete('cascade');
            $table->unique(['employee_id', 'duty_date']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_dailies');
    }
};
