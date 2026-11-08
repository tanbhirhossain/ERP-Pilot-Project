<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_skills', function (Blueprint $table): void {
            $table->id();
            $table->string('employee_id');
            $table->string('skill_name');
            $table->enum('proficiency', ['Beginner', 'Intermediate', 'Advanced', 'Expert'])->default('Intermediate');
            $table->integer('years_of_experience')->nullable();
            $table->text('description')->nullable();
            $table->foreign('employee_id')->references('employee_id')->on('employees')->onDelete('cascade');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_skills');
    }
};
