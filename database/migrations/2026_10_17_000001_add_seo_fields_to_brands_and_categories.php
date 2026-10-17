<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The owner's own words for a brand or category page: its title, its
     * search-result description and an intro shown above the questions.
     *
     * All optional. Left empty, the page keeps writing its own from live data
     * (price range, product count, best sellers) - see App\Support\CollectionSeo.
     */
    private const TABLES = ['product_brands', 'product_categories'];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (!Schema::hasColumn($tableName, 'seo_title')) {
                    $table->string('seo_title', 190)->nullable();
                }
                if (!Schema::hasColumn($tableName, 'seo_description')) {
                    $table->string('seo_description', 320)->nullable();
                }
                if (!Schema::hasColumn($tableName, 'seo_content')) {
                    $table->text('seo_content')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                foreach (['seo_title', 'seo_description', 'seo_content'] as $column) {
                    if (Schema::hasColumn($tableName, $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
