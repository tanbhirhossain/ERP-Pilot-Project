<?php

/**
 * Company settings table, and the per-user appearance columns — report item 42.
 *
 * Two storage decisions, both deliberate:
 *
 *   1. The company default lives in a key/value table rather than in `config/`. A config value needs
 *      a deploy to change, and "which colour scheme does this tenant use" is an answer an
 *      administrator gives from the settings screen, not a release engineer from a pull request.
 *      `core_settings` is generic on purpose: the next company-wide preference is a row, not a
 *      migration.
 *   2. The user override lives on `users` rather than in the same table. It is read on every request
 *      by `HandleInertiaRequests`, the user row is already loaded by the auth guard, and a nullable
 *      column costs nothing — whereas a second query per request for something the shell needs
 *      before first paint would cost on every page of the application.
 *
 * Both columns are nullable: NULL means "no opinion", and the resolver in `UiTheme` falls through to
 * the company default and then to the catalogue default. A NOT NULL column with a seeded default
 * would make every existing user an explicit chooser, and "reset to the company scheme" would then
 * need a write instead of a NULL.
 *
 * Pure DDL — `MigrationDataSeparationTest` scans every migration for data writes in `up()`.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 100)->unique();
            $table->text('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->string('ui_scheme', 40)->nullable();
            $table->string('ui_mode', 10)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['ui_scheme', 'ui_mode']);
        });

        Schema::dropIfExists('core_settings');
    }
};
