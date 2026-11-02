<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_supervisors', function (Blueprint $table): void {
            $table->id();

            $table->string('employee_id');
            $table->foreignId('supervisor_id')->nullable()->constrained('users')->nullOnDelete();
            
            $table->enum('supervisor_type', [
                'Supervisor', 
                'Manager', 
                'Incharge', 
                'Reporting Manager', 
                'Department Head'
            ])->nullable();

            $table->foreign('employee_id')->references('employee_id')->on('employees')->onDelete('cascade');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_supervisors');
    }
};