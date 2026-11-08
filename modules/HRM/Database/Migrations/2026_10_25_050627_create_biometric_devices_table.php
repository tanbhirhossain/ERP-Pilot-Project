<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('biometric_devices', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('device_name')->nullable();
            $table->string('ip_address');
            $table->integer('port')->default(4370);
            $table->string('serial_number')->nullable()->unique();
            $table->string('device_model')->nullable();
            $table->string('location')->nullable();
            $table->string('username')->nullable();
            $table->string('password')->nullable();
            $table->enum('connection_type', ['TCP', 'UDP'])->default('TCP');
            $table->enum('status', ['Active', 'Inactive', 'Maintenance'])->default('Active');
            $table->boolean('is_enabled')->default(true);
            $table->boolean('auto_sync_enabled')->default(false);
            $table->integer('sync_interval_minutes')->default(15);
            $table->timestamp('last_sync_at')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('biometric_devices');
    }
};
