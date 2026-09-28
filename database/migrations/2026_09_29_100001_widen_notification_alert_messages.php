<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The alert messages were VARCHAR(255). An SMS template longer than that -
     * easily reached in Bangla, or with a few placeholders - was refused by
     * MySQL, and the whole Notification Alert tab failed to save with nothing
     * but "A database error occurred".
     */
    public function up(): void
    {
        Schema::table('notification_alerts', function (Blueprint $table) {
            $table->text('mail_message')->nullable()->change();
            $table->text('sms_message')->nullable()->change();
            $table->text('push_notification_message')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('notification_alerts', function (Blueprint $table) {
            $table->string('mail_message')->nullable()->change();
            $table->string('sms_message')->nullable()->change();
            $table->string('push_notification_message')->nullable()->change();
        });
    }
};
