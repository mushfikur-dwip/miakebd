<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every correction made to a till sale after it was rung up: a reversal
     * or a change of payment method, with who made it, when, from where and
     * why. Rows are only ever inserted (see PosSaleChange), so the record of a
     * correction cannot be corrected away.
     */
    public function up(): void
    {
        Schema::create('pos_sale_changes', function (Blueprint $table) {
            $table->id();
            // No foreign key: the record has to outlive anything done to the order.
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('outlet_id')->nullable();
            $table->string('action', 10);
            $table->decimal('amount', 19, 6);
            $table->unsignedTinyInteger('from_method')->nullable();
            $table->unsignedTinyInteger('to_method')->nullable();
            $table->string('from_note', 200)->nullable();
            $table->string('to_note', 200)->nullable();
            $table->string('reason', 500);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['outlet_id', 'created_at']);
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_sale_changes');
    }
};
