<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_calculation_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('salary_sheet_id')->unique()->constrained('payroll_salary_sheets')->cascadeOnDelete();
            $table->json('snapshot_json');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_calculation_snapshots');
    }
};
