<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_pairs', function (Blueprint $table): void {
            $table->id();
            $table->string('employee_id');
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            
            $table->date('duty_date'); // শিফট শুরুর নির্দিষ্ট তারিখ (মিড-নাইট ক্রস করলেও এটি ফিক্সড থাকবে)
            $table->dateTime('punch_in');
            $table->dateTime('punch_out')->nullable(); // ৭২ ঘণ্টা বা মিড-নাইট ক্রস হ্যান্ডেল করবে
            
            // এই পেয়ারটি কি মূল ডিউটি নাকি অন-কল/ওভারটাইম
            $table->enum('duty_type', ['Primary', 'Overtime'])->default('Primary');
            $table->enum('shift_type_applied', ['Fixed', 'Flexible', 'Custom']);
            
            $table->decimal('worked_hours', 5, 2)->default(0); // শুধু এই নির্দিষ্ট পেয়ারের কর্মঘণ্টা

            $table->foreign('employee_id')->references('employee_id')->on('employees')->onDelete('cascade');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_pairs');
    }
};