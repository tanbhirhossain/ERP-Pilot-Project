<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('salary_increments')) {
            return;
        }

        Schema::table('salary_increments', function (Blueprint $table): void {
            if (! Schema::hasColumn('salary_increments', 'rejected_by')) {
                $table->foreignId('rejected_by')->nullable()->after('approved_by')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('salary_increments', 'rejected_at')) {
                $table->timestamp('rejected_at')->nullable()->after('approved_at');
            }
            if (! Schema::hasColumn('salary_increments', 'decision_comments')) {
                $table->text('decision_comments')->nullable()->after('reason');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('salary_increments')) {
            return;
        }

        Schema::table('salary_increments', function (Blueprint $table): void {
            if (Schema::hasColumn('salary_increments', 'decision_comments')) {
                $table->dropColumn('decision_comments');
            }
            if (Schema::hasColumn('salary_increments', 'rejected_at')) {
                $table->dropColumn('rejected_at');
            }
            if (Schema::hasColumn('salary_increments', 'rejected_by')) {
                $table->dropConstrainedForeignId('rejected_by');
            }
        });
    }
};
