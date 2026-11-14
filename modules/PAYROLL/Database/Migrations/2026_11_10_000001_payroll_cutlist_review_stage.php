<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cutlist lifecycle: draft → generated (review open, adjustments allowed) → approved (sign-off for the
     * final salary sheet). "locked" stays for cutlists an internal payroll run owns.
     */
    private array $statuses = ['draft', 'generated', 'approved', 'locked'];

    public function up(): void
    {
        if (Schema::hasTable('payroll_cutlists') && Schema::hasColumn('payroll_cutlists', 'status')) {
            Schema::table('payroll_cutlists', function (Blueprint $table): void {
                $table->enum('status', $this->statuses)->default('draft')->change();
            });
            Schema::table('payroll_cutlists', function (Blueprint $table): void {
                $table->string('approval_note', 1000)->nullable();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
            });
        }
        if (Schema::hasTable('payroll_settings') && ! Schema::hasColumn('payroll_settings', 'cutlist_review_required')) {
            Schema::table('payroll_settings', function (Blueprint $table): void {
                $table->boolean('cutlist_review_required')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('payroll_cutlists') && Schema::hasColumn('payroll_cutlists', 'status')) {
            DB::table('payroll_cutlists')->where('status', 'approved')->update(['status' => 'generated']);
            Schema::table('payroll_cutlists', function (Blueprint $table): void {
                $table->dropColumn(['approval_note', 'approved_at']);
            });
            Schema::table('payroll_cutlists', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('approved_by');
            });
            Schema::table('payroll_cutlists', function (Blueprint $table): void {
                $table->enum('status', ['draft', 'generated', 'locked'])->default('draft')->change();
            });
        }
        if (Schema::hasTable('payroll_settings') && Schema::hasColumn('payroll_settings', 'cutlist_review_required')) {
            Schema::table('payroll_settings', function (Blueprint $table): void {
                $table->dropColumn('cutlist_review_required');
            });
        }
    }
};
