<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Whether the person also gets their notifications by email (App\Enums\NotificationEmail). The same in every company.
            $table->string('notification_email', 10)->default('daily');
        });

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_notification_email_check CHECK (notification_email IN ('daily', 'off'))");

        Schema::table('memberships', function (Blueprint $table) {
            // Up to when the daily summary has covered this company's notifications for the person, so each
            // summary lists only what came after the last one, and nothing is listed twice.
            $table->timestamp('notifications_summarized_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->dropColumn('notifications_summarized_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notification_email');
        });
    }
};
