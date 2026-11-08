<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roster_duties', function (Blueprint $table): void {
            $table->id();
            $table->string('employee_id');
            $table->foreignId('shift_id')->nullable()->constrained('roster_shifts')->nullOnDelete();
            $table->time('scheduled_start_time')->nullable()->comment('Snapshot: roster start');
            $table->time('scheduled_end_time')->nullable()->comment('Snapshot: roster end');
            $table->date('roster_date');
            $table->dateTime('custom_start_datetime')->nullable();
            $table->dateTime('custom_end_datetime')->nullable();
            $table->enum('status', ['Scheduled', 'Working', 'Completed', 'Absent', 'Off', 'Swapped'])->default('Scheduled');
            $table->text('remarks')->nullable();
            $table->foreign('employee_id')->references('employee_id')->on('employees')->onDelete('cascade');
            $table->unique(['employee_id', 'roster_date']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roster_duties');
    }
};
