<?php

use App\Enums\Ask;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marks a product as sellable at the till but hidden from the website.
     *
     * Defaults to Ask::NO, so every product already in the shop stays public
     * and nothing changes until someone switches a product over in the product
     * form. Only Ask::YES hides a product - see Product::scopeStorefront(),
     * which treats anything else, including a NULL left by a direct import, as
     * public. Hiding is the deliberate choice; being visible is the default.
     */
    public function up(): void
    {
        if (Schema::hasColumn('products', 'pos_only')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->unsignedTinyInteger('pos_only')->default(Ask::NO)->after('status');
            // The storefront filters on this in every listing it runs.
            $table->index(['pos_only', 'status'], 'products_pos_only_status_index');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('products', 'pos_only')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_pos_only_status_index');
            $table->dropColumn('pos_only');
        });
    }
};
