<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Employee Documents Vault ─────────────────────────────
        Schema::create('hrm_employee_documents', function (Blueprint $t): void {
            $t->id();
            $t->string('employee_id')->index();
            $t->enum('document_type', [
                'NID', 'Passport', 'Birth Certificate', 'Photo', 'Appointment Letter', 'Contract',
                'Experience Certificate', 'Education Certificate', 'Nominee Document', 'TIN', 'Bank Statement',
                'Police Verification', 'Medical Certificate', 'Other',
            ])->index();
            $t->string('title');
            $t->string('file_path'); // storage/app/private/hrm-documents/{employee_id}/...
            $t->string('file_name');
            $t->string('mime_type')->nullable();
            $t->unsignedBigInteger('file_size')->default(0);
            $t->date('issued_date')->nullable();
            $t->date('expiry_date')->nullable()->index();
            $t->enum('verification_status', ['Pending', 'Verified', 'Rejected', 'Expired'])->default('Pending')->index();
            $t->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('verified_at')->nullable();
            $t->text('remarks')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();
            $t->foreign('employee_id')->references('employee_id')->on('employees')->cascadeOnDelete();
            $t->index(['employee_id', 'document_type', 'verification_status']);
        });

        // ── Asset Catalogue + Assignments ────────────────────────
        Schema::create('hrm_assets', function (Blueprint $t): void {
            $t->id();
            $t->string('asset_code')->unique(); // AST-00001
            $t->string('name');
            $t->enum('category', ['ID Card', 'Laptop', 'Mobile', 'SIM', 'Uniform', 'Locker', 'Tool', 'Vehicle', 'Access Card', 'Other'])->index();
            $t->string('serial_no')->nullable();
            $t->decimal('unit_cost', 12, 2)->default(0);
            $t->enum('status', ['In Stock', 'Assigned', 'Returned', 'Lost', 'Damaged', 'Disposed'])->default('In Stock')->index();
            $t->text('description')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('hrm_employee_assets', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('asset_id')->constrained('hrm_assets')->cascadeOnDelete();
            $t->string('employee_id')->index();
            $t->date('issued_date')->index();
            $t->date('expected_return_date')->nullable();
            $t->date('returned_date')->nullable();
            $t->enum('issue_condition', ['New', 'Good', 'Used'])->default('Good');
            $t->enum('return_condition', ['Good', 'Damaged', 'Lost'])->nullable();
            $t->decimal('recovery_amount', 12, 2)->default(0)->comment('Deduction on loss/damage for settlement');
            $t->text('remarks')->nullable();
            $t->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->foreign('employee_id')->references('employee_id')->on('employees')->cascadeOnDelete();
            $t->index(['employee_id', 'returned_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hrm_employee_assets');
        Schema::dropIfExists('hrm_assets');
        Schema::dropIfExists('hrm_employee_documents');
    }
};
