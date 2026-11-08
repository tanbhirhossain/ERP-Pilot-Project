<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('device_user_id')->nullable(); // e.g. "1", "UID-1001" - string to match device_users
            $table->string('employee_id')->nullable(); // এই পাঞ্চ কোন কর্মীর সাথে যুক্ত তা (যদি থাকে)
            $table->dateTime('punch_time'); // এক্স্যাক্ট পাঞ্চের সময়
            $table->foreignId('device_id')->nullable()->constrained('biometric_devices')->nullOnDelete();
            $table->enum('punch_type', ['IN', 'OUT', 'AUTO'])->default('AUTO');
            $table->boolean('is_processed')->default(false); // লজিক দিয়ে পেয়ার তৈরি হয়ে গেছে কি না
            $table->timestamps();
            $table->foreign('employee_id')->references('employee_id')->on('employees')->onDelete('set null');
            // device_user_id FK to device_users string column - add index for performance but FK is optional (some devices may send unknown IDs)
            $table->index('device_user_id');
            $table->index(['employee_id', 'punch_time']);
            $table->index('is_processed');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_logs');
    }
};
