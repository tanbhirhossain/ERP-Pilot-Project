<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_period_exclusions', function (Blueprint $table): void {
            $table->id();
            $table->string('employee_id');
            $table->unsignedSmallInteger('pay_year');
            $table->unsignedTinyInteger('pay_month');
            $table->text('reason');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->foreign('employee_id')->references('employee_id')->on('employees')->restrictOnDelete();
            $table->unique(['employee_id', 'pay_year', 'pay_month'], 'payroll_period_exclusions_employee_period_unique');
            $table->index(['pay_year', 'pay_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_period_exclusions');
    }
};
