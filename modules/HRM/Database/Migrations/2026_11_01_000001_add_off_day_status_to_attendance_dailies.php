<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $statuses = ['Present', 'Late', 'Early Out', 'Absent', 'On Leave', 'Holiday', 'Missing In', 'Missing Out', 'Extra Duty', 'Late & Early Out', 'Off Day'];

    public function up(): void
    {
        if (!Schema::hasTable('attendance_dailies') || !Schema::hasColumn('attendance_dailies', 'status')) return;

        Schema::table('attendance_dailies', function (Blueprint $table): void {
            $table->enum('status', $this->statuses)->default('Present')->change();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('attendance_dailies') || !Schema::hasColumn('attendance_dailies', 'status')) return;

        DB::table('attendance_dailies')->where('status', 'Off Day')->update(['status' => 'Absent']);
        Schema::table('attendance_dailies', function (Blueprint $table): void {
            $table->enum('status', array_slice($this->statuses, 0, -1))->default('Present')->change();
        });
    }
};
