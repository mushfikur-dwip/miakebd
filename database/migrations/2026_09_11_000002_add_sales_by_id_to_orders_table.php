<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The employee a POS sale is credited to, picked in the till's "Sale By"
     * box. Nullable: online orders have no salesperson, and POS orders placed
     * before this column recorded nobody - orders never filled creator_id, so
     * there is nothing to backfill from.
     *
     * nullOnDelete covers a hard-deleted user. Employees are soft-deleted, so in
     * practice someone who leaves keeps their sales.
     */
    public function up(): void
    {
        if (Schema::hasColumn('orders', 'sales_by_id')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('sales_by_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('orders', 'sales_by_id')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sales_by_id');
        });
    }
};
