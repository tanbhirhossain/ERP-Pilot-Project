<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employee certificate requests (Salary / NOC / Experience).
 *
 * Requested from the mobile app, issued by the HR desk (Phase 3 adds the HR
 * queue; the issue/reject transitions already live in
 * CertificateRequestService so the desk and the app share them).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hrm_certificate_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('employee_id');
            $table->enum('certificate_type', ['Salary Certificate', 'NOC Certificate', 'Experience Certificate']);
            $table->text('purpose');
            $table->enum('status', ['Pending', 'Issued', 'Rejected', 'Cancelled'])->default('Pending');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();

            $table->foreign('employee_id')->references('employee_id')->on('employees')->onDelete('cascade');
            $table->index(['employee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hrm_certificate_requests');
    }
};
