<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_dailies', function (Blueprint $table): void {
            $table->id();
            $table->string('employee_id');
            $table->date('duty_date'); // প্রতিদিনের জন্য ১টি ইউনিক রো
            
            // প্রাইমারি ডিউটির তথ্য
            $table->foreignId('primary_shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->time('expected_in_time')->nullable()->comment('স্ন্যাপশট: শিফট অনুযায়ী আসার কথা ছিল');
            $table->time('expected_out_time')->nullable()->comment('স্ন্যাপশট: শিফট অনুযায়ী যাওয়ার কথা ছিল');

            
            $table->dateTime('primary_punch_in')->nullable();
            $table->dateTime('primary_punch_out')->nullable();
            $table->decimal('primary_worked_hours', 5, 2)->default(0);
            
            // ওভারটাইম / অন-কল তথ্য (একই দিনের সমস্ত ওভারটাইমের যোগফল)
            $table->decimal('total_overtime_hours', 5, 2)->default(0);
            
            // দিনের সর্বমোট কাজের ঘণ্টা (Primary + Overtime)
            $table->decimal('grand_total_hours', 5, 2)->default(0);
            
            // হাজিরা স্ট্যাটাস
            $table->enum('status', ['Present', 'Late', 'Early Out', 'Absent', 'On Leave', 'Holiday', 'Missing In', 'Missing Out', 'Extra Duty'])->default('Present');
            $table->text('remarks')->nullable();

            $table->foreign('employee_id')->references('employee_id')->on('employees')->onDelete('cascade');
            
            // নিশ্চিত করবে যে ১ জন কর্মীর ১ দিনের জন্য ডাটাবেজে ১টিই রো থাকবে
            $table->unique(['employee_id', 'duty_date']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_dailies');
    }
};