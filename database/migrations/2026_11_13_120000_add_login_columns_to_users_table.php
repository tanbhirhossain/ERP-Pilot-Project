<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Employee auto-provision login needs two facts on the user row, and both
     * live here rather than on the employee because every guard in the app —
     * web session, Sanctum token, F13 exchange, SSO callback — resolves the
     * *user*, and the checks must not join to HRM on every one of them.
     *
     * `force_password_change` is the first-login gate: provision and HR-reset
     * set it, any successful password change clears it.
     *
     * `provisioned_employee_code` is provenance for the delete cascade: only a
     * user this feature created (code matches the employee being deleted) is
     * removed with it. A hand-linked account — an admin the directory points
     * at — is never touched.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('force_password_change')->default(false);
            $table->string('provisioned_employee_code', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['force_password_change', 'provisioned_employee_code']);
        });
    }
};
