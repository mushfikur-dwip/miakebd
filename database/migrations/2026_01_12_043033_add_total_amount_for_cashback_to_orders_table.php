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
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('total_amount_for_cashback', 25, 6)->default(0)->after('total');
            $table->decimal('wallet_discount', 25, 6)->default(0)->after('total_amount_for_cashback');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('total_amount_for_cashback');
            $table->dropColumn('wallet_discount');
        });
    }
};
