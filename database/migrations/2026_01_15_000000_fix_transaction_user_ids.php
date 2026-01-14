<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Update transactions to link user_id from associated order
        // This query joins transactions with orders and updates the transaction's user_id
        // where it is currently NULL
        DB::statement("
            UPDATE transactions t
            JOIN orders o ON t.order_id = o.id
            SET t.user_id = o.user_id
            WHERE t.user_id IS NULL
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No specific reversal needed as we don't want to revert data fixes usually,
        // and we don't know which ones were null before.
    }
};
