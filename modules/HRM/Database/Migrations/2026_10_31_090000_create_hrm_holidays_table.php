<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('hrm_holidays', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->date('holiday_date');
            $table->string('holiday_type', 40)->default('Public Holiday');
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['holiday_date','department_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('hrm_holidays'); }
};
