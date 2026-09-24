<?php

use App\Enums\Status;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gives every product a brand.
 *
 * Brand is about to become required on the product form, and right now every
 * row in the catalogue has product_brand_id NULL - so without this, saving any
 * existing product would first force someone to pick a brand for it.
 *
 * The fallback brand is flagged with a column rather than matched on its name
 * or slug: the shop owner can rename "No Brand" to anything, and a storefront
 * that recognised it by name would start showing it the moment they did.
 */
return new class extends Migration
{
    private const SLUG = 'no-brand';
    private const NAME = 'No Brand';

    public function up(): void
    {
        if (!Schema::hasColumn('product_brands', 'is_default')) {
            Schema::table('product_brands', function (Blueprint $table) {
                $table->boolean('is_default')->default(false)->after('slug');
            });
        }

        $id = DB::table('product_brands')
            ->where('slug', self::SLUG)
            ->orWhere('name', self::NAME)
            ->value('id');

        if ($id) {
            DB::table('product_brands')->where('id', $id)->update(['is_default' => true]);
        } else {
            $id = DB::table('product_brands')->insertGetId([
                'name'       => self::NAME,
                'slug'       => self::SLUG,
                'is_default' => true,
                // ACTIVE so it appears in the admin product dropdown - the shop
                // owner asked to still be able to pick it deliberately. It is
                // kept off the storefront by is_default, not by status.
                'status'     => Status::ACTIVE,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('products')
            ->whereNull('product_brand_id')
            ->update(['product_brand_id' => $id]);
    }

    public function down(): void
    {
        $id = DB::table('product_brands')->where('is_default', true)->value('id');

        if ($id) {
            DB::table('products')->where('product_brand_id', $id)->update(['product_brand_id' => null]);
            DB::table('product_brands')->where('id', $id)->delete();
        }

        if (Schema::hasColumn('product_brands', 'is_default')) {
            Schema::table('product_brands', function (Blueprint $table) {
                $table->dropColumn('is_default');
            });
        }
    }
};
