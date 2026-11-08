<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A partner's contact name, address line, bank name and account number were all NOT NULL, which
 * made the Contacts / Addresses / Banks tabs impossible to save half-filled: the editor always
 * ships one blank row, so creating a vendor with no bank account yet failed at the database even
 * once validation stopped asking for it. These four columns are details a partner may not have
 * told us yet, not identity, so they become nullable. The FormRequests and `pruneBlankRows()`
 * keep genuinely empty rows out of the tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_contacts', function (Blueprint $table): void {
            $table->string('name')->nullable()->change();
        });

        Schema::table('partner_addresses', function (Blueprint $table): void {
            $table->string('address_line')->nullable()->change();
        });

        Schema::table('partner_banks', function (Blueprint $table): void {
            $table->string('bank_name')->nullable()->change();
            $table->string('account_no')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Reverting re-imposes NOT NULL. Rows saved with a null in any of these columns would make
        // that fail, so backfill them with an empty string first — the pre-migration behaviour.
        Schema::table('partner_contacts', function (Blueprint $table): void {
            $table->string('name')->nullable(false)->default('')->change();
        });
        Schema::table('partner_addresses', function (Blueprint $table): void {
            $table->string('address_line')->nullable(false)->default('')->change();
        });
        Schema::table('partner_banks', function (Blueprint $table): void {
            $table->string('bank_name')->nullable(false)->default('')->change();
            $table->string('account_no')->nullable(false)->default('')->change();
        });
    }
};
