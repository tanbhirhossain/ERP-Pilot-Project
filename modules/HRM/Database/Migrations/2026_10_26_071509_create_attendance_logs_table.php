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
            $table->unsignedBigInteger('device_user_id');
            $table->string('employee_id')->nullable(); // এই পাঞ্চ কোন কর্মীর সাথে যুক্ত তা (যদি থাকে)
            $table->dateTime('punch_time'); // এক্স্যাক্ট পাঞ্চের সময়
            $table->string('device_id')->nullable(); // কোন মেশিন বা বায়োমেট্রিক থেকে পাঞ্চ এসেছে
            $table->enum('punch_type', ['IN', 'OUT', 'AUTO'])->default('AUTO');
            $table->boolean('is_processed')->default(false); // লজিক দিয়ে পেয়ার তৈরি হয়ে গেছে কি না

            $table->timestamps();

            $table->foreign('employee_id')->references('employee_id')->on('employees')->onDelete('set null');
            $table->foreign('device_user_id')->references('device_user_id')->on('device_users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_logs');
    }
};