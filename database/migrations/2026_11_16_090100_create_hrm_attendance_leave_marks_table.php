<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hrm_attendance_leave_marks')) {
            return;
        }

        Schema::create('hrm_attendance_leave_marks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_request_id')->constrained('leave_requests')->cascadeOnDelete();
            $table->string('employee_id');
            $table->date('duty_date');
            $table->boolean('half_day')->default(false);
            $table->string('previous_status')->nullable();
            $table->timestamps();

            $table->unique(['leave_request_id', 'employee_id', 'duty_date'], 'leave_mark_unique');
            $table->index(['employee_id', 'duty_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hrm_attendance_leave_marks');
    }
};
