<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The ledger. Rows are only ever inserted - a balance is the running
        // total of its rows, and a mistake is corrected by a reversal row, never
        // by editing one. No updated_at for that reason.
        Schema::create('cash_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('outlet_id');
            $table->unsignedTinyInteger('account');
            $table->unsignedTinyInteger('type');
            $table->decimal('amount', 19, 6);
            $table->decimal('balance_after', 19, 6);
            // No foreign key: the row has to outlive a deleted order, since the
            // reversal a deletion posts is the whole point of keeping it.
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('reverses_id')->nullable();
            $table->uuid('group_ref')->nullable();
            $table->string('reference', 100)->nullable();
            $table->string('party', 190)->nullable();
            $table->string('note', 500)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['outlet_id', 'account', 'id']);
            $table->index(['outlet_id', 'created_at']);
            $table->index('order_id');
            $table->index('reverses_id');
            $table->index('group_ref');
        });

        Schema::create('cash_counts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('outlet_id');
            $table->unsignedTinyInteger('account');
            $table->decimal('expected', 19, 6);
            $table->decimal('counted', 19, 6);
            $table->decimal('variance', 19, 6);
            $table->text('denominations')->nullable();
            $table->unsignedBigInteger('entry_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['outlet_id', 'account', 'id']);
        });

        // One row per branch once its PIN has been changed. A branch without a
        // row uses the default PIN, so a new branch needs no setup.
        Schema::create('cash_pins', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('outlet_id')->unique();
            $table->string('pin_hash');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('cash_pin_failures', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('outlet_id');
            $table->string('action', 30);
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'created_at']);
            $table->index(['outlet_id', 'created_at']);
        });

        Schema::table('outlets', function (Blueprint $table) {
            $table->boolean('mfs_enabled')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('outlets', function (Blueprint $table) {
            $table->dropColumn('mfs_enabled');
        });
        Schema::dropIfExists('cash_pin_failures');
        Schema::dropIfExists('cash_pins');
        Schema::dropIfExists('cash_counts');
        Schema::dropIfExists('cash_entries');
    }
};
