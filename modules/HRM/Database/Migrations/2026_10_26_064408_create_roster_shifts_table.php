<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roster_shifts', function (Blueprint $table): void {
            $table->id();
            $table->string('shift_name');
            
            //FOR FIXED SHIFTING
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();

            //FOR FLEXIBLE SHIFTING
            $table->time('punch_in_window_start')->nullable();
            $table->time('punch_in_window_end')->nullable();

            //FOR IF CROSS MIDNIGHT SHIFT
            $table->boolean('crosses_midnight')->default(false);

            //FOR CUSTOM SHIFTING
            $table->integer('max_duration_hours')->default(24);

            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roster_shifts');
    }
};