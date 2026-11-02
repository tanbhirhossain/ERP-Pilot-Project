<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('attendance_sync_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->constrained('biometric_devices')->onDelete('cascade');
            $table->boolean('auto_sync_enabled')->default(false);
            $table->integer('sync_interval_minutes')->default(15);
            $table->integer('sync_window_hours')->default(24)->comment('How many hours back to fetch on each sync');
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamp('next_sync_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique('device_id');
        });
        Schema::create('attendance_sync_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->nullable()->constrained('biometric_devices')->nullOnDelete();
            $table->enum('status', ['Success','Failed','Partial'])->default('Success');
            $table->integer('records_fetched')->default(0);
            $table->integer('records_processed')->default(0);
            $table->text('message')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('attendance_sync_logs');
        Schema::dropIfExists('attendance_sync_settings');
    }
};
