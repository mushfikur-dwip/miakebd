<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The outbox for Meta's Conversions API.
     *
     * A request only writes a row here - milliseconds - and `meta:send-events`
     * posts whatever is pending to Meta once a minute, in one batch. That keeps
     * every storefront request free of an outbound call to Facebook, which on a
     * shared host with a synchronous queue is what an ad-driven crowd would
     * otherwise queue up behind.
     *
     * Rows are short-lived: sent ones are deleted after a day, and unsent ones
     * after seven, which is the oldest event Meta accepts.
     */
    public function up(): void
    {
        if (Schema::hasTable('meta_events')) {
            return;
        }

        Schema::create('meta_events', function (Blueprint $table) {
            $table->id();
            // Shared with the browser's copy of the same event; Meta keeps one.
            // Unique so a purchase can never be queued twice for one order.
            $table->string('event_id', 64)->unique();
            $table->string('event_name', 40);
            // The complete Conversions API event, user_data already hashed.
            $table->longText('payload');
            // False for an online-payment order's Purchase until the payment
            // succeeds. It is written while the customer's own request is in
            // hand - their IP, browser and click id - because the payment
            // callback that later confirms it may come from the gateway's
            // servers, and matching on those would credit nobody.
            $table->boolean('ready')->default(true);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('created_at')->nullable();

            // The sender's query: ready, unsent rows, oldest first.
            $table->index(['ready', 'sent_at', 'id'], 'meta_events_pending_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_events');
    }
};
