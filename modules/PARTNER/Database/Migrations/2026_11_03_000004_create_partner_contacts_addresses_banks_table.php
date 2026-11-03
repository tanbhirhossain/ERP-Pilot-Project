<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('partner_id')->constrained('partners')->cascadeOnDelete();
            $table->string('name');
            $table->string('designation')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('can_receive_statement')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['partner_id', 'is_primary']);
        });

        Schema::create('partner_addresses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('partner_id')->constrained('partners')->cascadeOnDelete();
            $table->enum('type', ['billing', 'shipping', 'factory', 'warehouse', 'branch', 'other'])->default('billing');
            $table->string('label')->nullable();
            $table->string('address_line');
            $table->string('area')->nullable();
            $table->string('city')->nullable();
            $table->string('district')->nullable();
            $table->string('division')->nullable();
            $table->string('country')->default('Bangladesh');
            $table->string('postal_code')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['partner_id', 'type']);
            $table->index('district');
        });

        Schema::create('partner_banks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('partner_id')->constrained('partners')->cascadeOnDelete();
            $table->string('bank_name');
            $table->string('branch')->nullable();
            $table->string('account_name')->nullable();
            $table->string('account_no');
            $table->string('routing_no')->nullable();
            $table->string('swift_code')->nullable();
            $table->string('account_type')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['partner_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_banks');
        Schema::dropIfExists('partner_addresses');
        Schema::dropIfExists('partner_contacts');
    }
};
