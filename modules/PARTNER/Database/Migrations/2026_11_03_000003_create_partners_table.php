<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partners', function (Blueprint $table): void {
            $table->id();
            $table->string('partner_code')->unique();
            $table->enum('type', ['vendor', 'customer', 'both'])->index();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->foreignId('group_id')->nullable()->constrained('partner_groups')->nullOnDelete();
            $table->foreignId('price_list_id')->nullable()->constrained('price_lists')->nullOnDelete();

            // BD compliance
            $table->string('trade_license')->nullable();
            $table->string('tin')->nullable();
            $table->string('bin')->nullable();
            $table->string('vat_reg_no')->nullable();
            $table->string('nid')->nullable();

            // Contact (primary)
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();

            // Commercial
            $table->decimal('credit_limit', 15, 2)->default(0);
            $table->unsignedSmallInteger('payment_terms_days')->default(0);
            $table->string('payment_terms')->nullable();
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->decimal('current_balance', 15, 2)->default(0);
            $table->string('currency', 3)->default('BDT');

            // Status
            $table->boolean('is_active')->default(true);
            $table->boolean('is_verified')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('verification_status', ['pending', 'approved', 'rejected'])->default('pending');

            // Ownership
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->json('meta')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['type', 'is_active']);
            $table->index('group_id');
            $table->index('phone');
            $table->index('email');
            $table->index('is_verified');
            if (DB::connection()->getDriverName() !== 'sqlite') $table->fullText(['name', 'legal_name', 'partner_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partners');
    }
};
