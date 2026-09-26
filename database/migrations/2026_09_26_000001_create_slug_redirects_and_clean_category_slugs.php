<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Category URLs like /product-category/baby-careNULL and /skin-care6.
     *
     * Saving a category looked its slug up to see whether it was taken - and
     * always found the category itself - so every save appended the parent id,
     * or the literal "NULL" for a top-level category, and the next save took
     * it off again. Each edit moved the page to a new URL and broke the old
     * one. ProductCategoryService no longer does that; this puts the damaged
     * slugs back to the plain name and remembers the old ones, which now
     * answer with a 301 to the new address instead of a 404.
     */
    public function up(): void
    {
        if (!Schema::hasTable('slug_redirects')) {
            Schema::create('slug_redirects', function (Blueprint $table) {
                $table->id();
                $table->string('type', 40);
                $table->string('from_slug', 191);
                $table->string('to_slug', 191);
                $table->timestamps();

                $table->unique(['type', 'from_slug']);
            });
        }

        // Invisible control characters pasted into names ("\x1DSkin Care"),
        // which reached page titles and headings. ProductCategoryRequest now
        // strips them on save; this cleans the ones already stored.
        foreach (DB::table('product_categories')->select(['id', 'name'])->get() as $row) {
            $clean = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', '', (string) $row->name));

            if ($clean !== '' && $clean !== $row->name) {
                DB::table('product_categories')->where('id', $row->id)->update(['name' => $clean]);
            }
        }

        $categories = DB::table('product_categories')->select(['id', 'name', 'slug'])->orderBy('id')->get();
        $taken      = $categories->pluck('slug')->filter()->all();

        foreach ($categories as $category) {
            $base = Str::slug((string) $category->name);

            // Only the bug's own signature: the name's slug with "NULL" or a
            // number glued on. Anything else is left exactly as it is.
            if ($base === '' || $category->slug === $base || !preg_match('/^' . preg_quote($base, '/') . '(NULL|\d+)$/', (string) $category->slug)) {
                continue;
            }

            $target = $base;
            $n      = 2;
            while (in_array($target, $taken, true)) {
                $target = $base . '-' . $n++;
            }

            DB::table('product_categories')->where('id', $category->id)->update(['slug' => $target]);

            DB::table('slug_redirects')->updateOrInsert(
                ['type' => 'product_category', 'from_slug' => $category->slug],
                ['to_slug' => $target, 'created_at' => now(), 'updated_at' => now()]
            );

            $taken = array_values(array_diff($taken, [$category->slug]));
            $taken[] = $target;

            try {
                Cache::forget('suglow_category_meta:' . $category->slug);
                Cache::forget('suglow_category_meta:' . $target);
            } catch (\Throwable $e) {
                // The copies expire on their own.
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('slug_redirects');
    }
};
