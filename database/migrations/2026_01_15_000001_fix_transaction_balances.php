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
        // Update transactions to set balance_after to the user's current balance
        // where it is currently NULL or 0, for records that have a user_id
        DB::statement("
            UPDATE transactions t
            JOIN users u ON t.user_id = u.id
            SET t.balance_after = u.balance
            WHERE (t.balance_after IS NULL OR t.balance_after = 0)
            AND t.user_id IS NOT NULL
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No reversal
    }
};
