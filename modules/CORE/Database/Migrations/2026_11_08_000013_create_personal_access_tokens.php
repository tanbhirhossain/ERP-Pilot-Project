<?php

/**
 * F13 — Sanctum's personal access token table, kept as an application-owned
 * migration instead of a published vendor copy.
 *
 * The schema is Sanctum's own, verbatim, so `PersonalAccessToken` keeps working
 * and a future `vendor:publish` is a no-op rather than a conflict. It lives here
 * because every other schema in this repo is module-owned: a table that only
 * exists in `vendor/` would be invisible to `php artisan migrate:fresh --path`
 * flows, to the migration/data-separation guard, and to the module audit.
 *
 * `tokenable` is a morph, so no foreign key can express "revoke when the user
 * goes away" (see the `User::deleting` hook in COREServiceProvider, which does
 * it at the model level and is covered by Phase3ApiContractTest).
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
