<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roster_duties', function (Blueprint $table): void {
            $table->id();
            $table->string('employee_id');
            
            // কোন শিফটে ডিউটি (Fixed বা Custom শিফটের ক্ষেত্রে এটি বাধ্যতামূলক হতে পারে)
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete();

            //SNAPSHOT: রোস্টার তৈরির সময়ের শিফট ইন এবং আউট টাইম (যদি শিফট পরিবর্তন হয়, তখনও রেকর্ড থাকবে)
            $table->time('scheduled_start_time')->nullable()->comment('Snapshot: রস্টার তৈরির সময়ের শিফট ইন টাইম');
            $table->time('scheduled_end_time')->nullable()->comment('Snapshot: রস্টার তৈরির সময়ের শিফট আউট টাইম');
            
            // রোস্টারের নির্দিষ্ট তারিখ (কোন দিনের শিফট তা বোঝাতে)
            $table->date('roster_date'); 

            // কাস্টম শিফট বা লং ডিউটির (যেমন: ১২ থেকে ৭২ ঘণ্টা) জন্য সুনির্দিষ্ট সময়সীমা
            // এটি ব্যবহার করলে ফিক্সড শিফটের বাইরেও মাল্টি-ডে শিফট ম্যানেজ করা সহজ হবে
            $table->dateTime('custom_start_datetime')->nullable();
            $table->dateTime('custom_end_datetime')->nullable();

            

            // শিফটের বর্তমান স্ট্যাটাস
            $table->enum('status', [
                'Scheduled', // শিফট নির্ধারিত আছে
                'Working',   // ডিউটি রানিং আছে
                'Completed', // ডিউটি শেষ হয়েছে
                'Absent',    // অনুপস্থিত
                'Off',       // সাপ্তাহিক ছুটি বা লিভ
                'Swapped'    // শিফট পরিবর্তন করা হয়েছ
            ])->default('Scheduled');

            $table->text('remarks')->nullable(); // কোনো নোট বা মন্তব্য থাকলে

            // রিলেশনশিপ
            $table->foreign('employee_id')->references('employee_id')->on('employees')->onDelete('cascade');
            
            // একই কর্মীকে একই দিনে যেন দুটি প্রাইমারি রোস্টার অ্যাসাইন করা না যায়
            $table->unique(['employee_id', 'roster_date']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roster_duties');
    }
};