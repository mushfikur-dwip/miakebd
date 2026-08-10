<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Branch-wise stock. Every stock movement (purchase in, POS sale out,
     * adjustment, transfer) now records which outlet it happened at.
     *
     * NULL means "unassigned" - that is every row written before this
     * migration, plus website delivery orders, which are not tied to a
     * branch. The online storefront keeps summing every row regardless of
     * outlet, so the total it shows is unchanged by this migration.
     */
    public function up(): void
    {
        Schema::table('stocks', function (Blueprint $table) {
            $table->foreignId('outlet_id')->nullable()->after('product_id')->constrained('outlets')->nullOnDelete();

            // Covers the per-branch sum the POS runs for every product tile:
            // WHERE item_type = ? AND item_id = ? AND outlet_id = ? AND status = ?
            $table->index(['item_type', 'item_id', 'outlet_id', 'status'], 'stocks_outlet_item_index');
        });
    }

    public function down(): void
    {
        Schema::table('stocks', function (Blueprint $table) {
            $table->dropIndex('stocks_outlet_item_index');
            $table->dropConstrainedForeignId('outlet_id');
        });
    }
};
