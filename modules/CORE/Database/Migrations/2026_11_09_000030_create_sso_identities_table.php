<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SSO — external identities linked to accounts (Phase 3).
 *
 * One row per (provider, provider user id). The provider's stable subject is the identity; the email is
 * copied in for display and for the "is there already an account for this person?" lookup, but it is not
 * the key — addresses get reassigned by IT departments, subject ids do not.
 *
 * `user_id` is nullable on purpose: an identity that was refused (domain not allowed, provisioning off)
 * is still worth a row when the *attempt* matters? No — refused attempts are logged as audit events, not
 * stored as identities. The column is nullable for the opposite reason: an identity may be created by an
 * admin import before the account exists. Nothing in this slice writes it that way, so the FK is plain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sso_identities', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 40);
            $table->string('provider_user_id', 191);
            $table->string('email', 191);
            $table->string('name')->nullable();
            $table->string('avatar_url', 512)->nullable();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_user_id']);
            $table->index(['provider', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sso_identities');
    }
};
