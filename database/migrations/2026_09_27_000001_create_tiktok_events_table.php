<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The outbox for TikTok's Events API - the same design as `meta_events`:
     * a request only writes a row, and `tiktok:send-events` posts whatever is
     * pending once a minute. Sent rows are deleted after a day, unsent ones
     * after seven.
     */
    public function up(): void
    {
        if (Schema::hasTable('tiktok_events')) {
            return;
        }

        Schema::create('tiktok_events', function (Blueprint $table) {
            $table->id();
            // Shared with the browser pixel's copy; TikTok keeps one.
            $table->string('event_id', 64)->unique();
            $table->string('event_name', 40);
            // The complete Events API event, user details already hashed.
            $table->longText('payload');
            // False for an online-payment Purchase until the payment succeeds.
            $table->boolean('ready')->default(true);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['ready', 'sent_at', 'id'], 'tiktok_events_pending_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiktok_events');
    }
};
