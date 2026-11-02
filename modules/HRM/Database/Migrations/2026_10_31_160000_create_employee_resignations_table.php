<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_resignations', function (Blueprint $table): void {
            $table->id();
            $table->string('employee_id');
            $table->date('last_working_date');
            $table->text('reason');
            $table->enum('status', ['Pending', 'Approved', 'Rejected'])->default('Pending');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();
            $table->foreign('employee_id')->references('employee_id')->on('employees')->restrictOnDelete();
            $table->index(['employee_id', 'status', 'last_working_date']);
            $table->index(['status', 'last_working_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_resignations');
    }
};
