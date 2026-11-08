<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_fiscal_years', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique(); // FY 2025-2026
            $t->string('code')->unique(); // FY2526
            $t->date('start_date');
            $t->date('end_date');
            $t->enum('status', ['draft', 'open', 'closed', 'locked'])->default('open');
            $t->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('closed_at')->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['status', 'start_date']);
        });

        Schema::create('acc_fiscal_periods', function (Blueprint $t) {
            $t->id();
            $t->foreignId('fiscal_year_id')->constrained('acc_fiscal_years')->cascadeOnDelete();
            $t->string('name'); // July 2025
            $t->unsignedTinyInteger('month_no'); // 7
            $t->date('start_date');
            $t->date('end_date');
            $t->enum('status', ['open', 'closed', 'locked'])->default('open');
            $t->timestamps();
            $t->unique(['fiscal_year_id', 'month_no']);
            $t->index(['status', 'start_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_fiscal_periods');
        Schema::dropIfExists('acc_fiscal_years');
    }
};
