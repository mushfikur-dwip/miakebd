<?php
/**
 * suglow.com — add SEO columns to products
 *
 * UPLOAD TO:  dev/database/migrations/2026_07_28_120000_add_seo_columns_to_products.php
 *             ^ keep this exact filename — the timestamp controls run order
 *
 * RUN:  php artisan migrate
 *
 * BUG 7 FIX: the old filename was dated 2026_01_01, which sorts BEFORE the
 * migration that creates the products table. On `migrate:fresh` it would have
 * tried to alter a table that did not exist yet. Now dated today, plus a
 * hasTable() guard.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products')) {
            // nothing to alter yet — safe no-op
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'meta_title')) {
                $table->string('meta_title', 70)->nullable();
            }
            if (! Schema::hasColumn('products', 'meta_description')) {
                $table->text('meta_description')->nullable();
            }
            if (! Schema::hasColumn('products', 'meta_keywords')) {
                $table->text('meta_keywords')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            foreach (['meta_title', 'meta_description', 'meta_keywords'] as $col) {
                if (Schema::hasColumn('products', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
