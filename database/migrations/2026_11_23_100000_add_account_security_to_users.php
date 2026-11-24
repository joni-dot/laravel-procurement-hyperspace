<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // A new email address waits here until its owner opens the link sent to it, so nobody can
            // take over an address (and the invitations sent to it) by typing it into their profile.
            $table->string('pending_email')->nullable()->after('email');
            // Goes up when the ways to sign in change (two-factor sign-in, passkeys); every session
            // signed in before then is ended (EndOutdatedSessions).
            $table->unsignedInteger('sign_in_version')->default(0)->after('remember_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['pending_email', 'sign_in_version']);
        });
    }
};
