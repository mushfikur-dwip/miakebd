<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('wallet_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('cashback_status')->default(false);
            $table->string('cashback_rule')->default('cart_wise'); // cart_wise
            $table->string('cashback_type')->default('percentage'); // percentage or fixed
            $table->decimal('cashback_amount', 10, 2)->default(0);
            $table->decimal('max_cashback_amount', 10, 2)->nullable();
            $table->json('payment_methods')->nullable(); // ['cash_on_delivery', 'bkash', etc]
            $table->string('process_cashback')->default('delivered'); // when to process: delivered
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wallet_settings');
    }
};
