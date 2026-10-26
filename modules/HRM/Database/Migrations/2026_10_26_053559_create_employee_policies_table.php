<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_policies', function (Blueprint $table): void {
            $table->id();
            $table->string('employee_id');

            $table->integer('grace_time_in')->nullable();
            $table->integer('grace_time_out')->nullable();

            $table->enum('shift_type', ['Fixed', 'Flexible', 'Custom'])
                  ->nullable()
                  ->comment('Fixed: Fixed shift, Flexible: Flexible shift, Custom: Custom shift for Doctors and Nurses for 12 to 72 hours shift');
            
            $table->boolean('is_punishable')
                  ->default(true)
                  ->comment('If true, employee will be punished for late in and early out');

            $table->foreign('employee_id')->references('employee_id')->on('employees')->onDelete('cascade');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_policies');
    }
};