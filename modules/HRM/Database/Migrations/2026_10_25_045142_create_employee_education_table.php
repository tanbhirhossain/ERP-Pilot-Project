<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_education', function (Blueprint $table): void {
            $table->id();
            $table->string('employee_id');
            $table->string('degree')->nullable();
            $table->string('institute')->nullable();
            $table->string('board')->nullable();
            $table->string('passing_year')->nullable();
            $table->string('grade')->nullable();
            $table->string('gpa')->nullable();
            $table->text('description')->nullable();
            $table->foreign('employee_id')->references('employee_id')->on('employees')->onDelete('cascade');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_education');
    }
};
