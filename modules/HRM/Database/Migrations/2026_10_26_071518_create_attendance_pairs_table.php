<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_pairs', function (Blueprint $table): void {
            $table->id();
            $table->string('employee_id');
            $table->foreignId('shift_id')->nullable()->constrained('roster_shifts')->nullOnDelete();
            $table->date('duty_date');
            $table->dateTime('punch_in');
            $table->dateTime('punch_out')->nullable();
            $table->enum('duty_type', ['Primary', 'Overtime'])->default('Primary');
            $table->enum('shift_type_applied', ['Fixed', 'Flexible', 'Custom'])->default('Fixed');
            $table->decimal('worked_hours', 5, 2)->default(0);
            $table->foreign('employee_id')->references('employee_id')->on('employees')->onDelete('cascade');
            $table->timestamps();
            $table->index(['employee_id', 'duty_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_pairs');
    }
};
