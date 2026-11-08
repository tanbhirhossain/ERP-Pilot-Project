<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_fallback_off_days', function (Blueprint $table): void {
            $table->id();
            $table->string('employee_id')->unique();
            // Carbon weekday numbering: Sunday=0 through Saturday=6.
            $table->unsignedTinyInteger('day_of_week');
            $table->timestamps();
            $table->foreign('employee_id')->references('employee_id')->on('employees')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_fallback_off_days');
    }
};
