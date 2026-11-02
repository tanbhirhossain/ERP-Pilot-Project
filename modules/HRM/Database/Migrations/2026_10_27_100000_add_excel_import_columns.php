<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Employees: add legacy Excel columns missing from current schema
        Schema::table('employees', function (Blueprint $table): void {
            if (!Schema::hasColumn('employees', 'file_no')) {
                $table->string('file_no')->nullable()->after('employee_id');
            }
            if (!Schema::hasColumn('employees', 'card_issue_date')) {
                $table->date('card_issue_date')->nullable()->after('joining_date');
            }
            if (!Schema::hasColumn('employees', 'job_type')) {
                $table->string('job_type')->nullable()->after('employment_type')->comment('provisional, permanent etc from Excel Job Type');
            }
            if (!Schema::hasColumn('employees', 'joining_salary')) {
                $table->decimal('joining_salary', 12, 2)->nullable()->after('job_type');
            }
            if (!Schema::hasColumn('employees', 'allow_overtime')) {
                $table->boolean('allow_overtime')->default(false)->after('joining_salary');
            }
            if (!Schema::hasColumn('employees', 'allow_bonus')) {
                $table->boolean('allow_bonus')->default(false)->after('allow_overtime');
            }
            if (!Schema::hasColumn('employees', 'payment_criteria')) {
                $table->string('payment_criteria')->nullable()->default('general')->after('allow_bonus');
            }
            if (!Schema::hasColumn('employees', 'service_year')) {
                $table->integer('service_year')->nullable()->after('payment_criteria');
            }
        });

        // Employee salaries: conveyance + bank/payment fields from Excel
        Schema::table('employee_salaries', function (Blueprint $table): void {
            if (!Schema::hasColumn('employee_salaries', 'conveyance_allowance')) {
                $table->decimal('conveyance_allowance', 12, 2)->default(0)->after('medical_allowance');
            }
            if (!Schema::hasColumn('employee_salaries', 'payment_method')) {
                $table->string('payment_method')->nullable()->after('net_salary')->comment('cash/bank');
            }
            if (!Schema::hasColumn('employee_salaries', 'bank_name')) {
                $table->string('bank_name')->nullable()->after('payment_method');
            }
            if (!Schema::hasColumn('employee_salaries', 'bank_account_no')) {
                $table->string('bank_account_no')->nullable()->after('bank_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn(['file_no','card_issue_date','job_type','joining_salary','allow_overtime','allow_bonus','payment_criteria','service_year']);
        });
        Schema::table('employee_salaries', function (Blueprint $table): void {
            $table->dropColumn(['conveyance_allowance','payment_method','bank_name','bank_account_no']);
        });
    }
};
