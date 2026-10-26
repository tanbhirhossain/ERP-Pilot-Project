<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_users', function (Blueprint $table): void {
            $table->id();
            $table->string('uid');
            $table->string('device_user_id')->unique(); // ডিভাইস থেকে আসা ইউনিক আইডি
            $table->string('employee_id')->nullable(); // এই ডিভাইস ইউজার কোন কর্মীর সাথে যুক্ত তা (যদি থাকে)
            $table->string('name')->nullable(); // ডিভাইস থেকে আসা নাম (যদি থাকে)
            

            $table->foreign('employee_id')->references('employee_id')->on('employees')->onDelete('set null');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_users');
    }
};