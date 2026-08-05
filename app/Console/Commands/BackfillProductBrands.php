<?php

namespace App\Console\Commands;

use App\Enums\Status;
use App\Models\Product;
use App\Models\ProductBrand;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Populate product_brands and attach each product to its real brand.
 *
 * Google's Merchant listing report asks for a "global identifier (e.g. gtin,
 * brand)". Products with a genuine manufacturer barcode satisfy that through
 * gtin13; the rest need a brand. The table was completely empty and no product
 * had product_brand_id set, so nothing could be emitted.
 *
 * The brand is read out of the product name — these are third-party goods and
 * the name reliably starts with, or contains, the manufacturer. Suglow is the
 * retailer, NOT the brand, so it is deliberately never used as one: claiming
 * ownership of another company's product line would be wrong on the storefront
 * and a policy problem in Merchant Center.
 */
class BackfillProductBrands extends Command
{
    protected $signature = 'brands:backfill
                            {--dry-run : Print what would change and write nothing}';

    protected $description = 'Seed real product brands and attach them to products by name';

    /**
     * Brands actually stocked. The first group is the list the site itself
     * advertises in its homepage FAQ; the rest are visible in product names.
     *
     * @var array<int,string>
     */
    private const BRANDS = [
        'Nivea', 'Dove', 'Garnier', 'Vaseline', 'CeraVe', 'Fogg', 'Lotus',
        'Enchanteur', 'Bioaqua', 'Sadoer', 'Sunsilk', 'Pantene', 'Johnson',
        'Skin1004', 'JJ White', 'Himalaya', 'Ponds', 'Loreal', 'Neutrogena',
        'Olay', 'Clean & Clear', 'Head & Shoulders', 'Rexona', 'Lux',
        'Parachute', 'Meril', 'Kumarika', 'Emami', 'Fair & Lovely', 'Glow & Lovely',
        'Simple', 'St. Ives', 'Aveeno', 'Cetaphil', 'Eucerin', 'Bioderma',
        'The Ordinary', 'Some By Mi', 'Cosrx', 'Innisfree', 'Laneige',
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Longest first: otherwise "Dove" would claim "Dove Body Love" before
        // a more specific brand had a chance, and "Clean & Clear" would never
        // match because a shorter name matched earlier in the string.
        $brands = collect(self::BRANDS)
            ->unique()
            ->sortByDesc(fn ($name) => mb_strlen($name))
            ->values();

        $products = Product::query()
            ->where('status', Status::ACTIVE)
            ->whereNull('product_brand_id')
            ->orderBy('id')
            ->get(['id', 'name', 'product_brand_id']);

        $matches = [];
        $unmatched = 0;

        foreach ($products as $product) {
            $brand = $this->matchBrand((string) $product->name, $brands);

            if ($brand === null) {
                $unmatched++;
                continue;
            }

            $matches[] = ['id' => $product->id, 'name' => $product->name, 'brand' => $brand];
        }

        $used = collect($matches)->pluck('brand')->unique()->sort()->values();

        $this->info('Products without a brand : ' . $products->count());
        $this->info('Matched to a brand       : ' . count($matches));
        $this->info('No brand found in name   : ' . $unmatched);
        $this->info('Distinct brands needed   : ' . $used->count());

        if (empty($matches)) {
            $this->warn('Nothing matched — no changes.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Product', 'Brand'],
            array_map(fn ($m) => [
                $m['id'],
                mb_strimwidth($m['name'], 0, 55, '…'),
                $m['brand'],
            ], array_slice($matches, 0, 25))
        );

        if (count($matches) > 25) {
            $this->line('  … and ' . (count($matches) - 25) . ' more.');
        }

        $this->line('Brands to create/reuse: ' . $used->implode(', '));

        if ($dryRun) {
            $this->warn('Dry run — nothing was written. Re-run without --dry-run to apply.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($matches, $used) {
            $ids = [];

            foreach ($used as $name) {
                $brand = ProductBrand::firstOrCreate(
                    ['slug' => Str::slug($name)],
                    ['name' => $name, 'status' => Status::ACTIVE]
                );

                $ids[$name] = $brand->id;
            }

            foreach ($matches as $m) {
                DB::table('products')
                    ->where('id', $m['id'])
                    ->update(['product_brand_id' => $ids[$m['brand']]]);
            }
        });

        $this->info('Created/reused ' . $used->count() . ' brands and updated ' . count($matches) . ' products.');

        return self::SUCCESS;
    }

    /**
     * @param  \Illuminate\Support\Collection<int,string>  $brands
     */
    private function matchBrand(string $productName, $brands): ?string
    {
        // Normalise separators so "Clean&Clear" and "Clean & Clear" both hit.
        $haystack = ' ' . mb_strtolower(preg_replace('/[^a-z0-9&]+/i', ' ', $productName)) . ' ';

        foreach ($brands as $brand) {
            $needle = ' ' . mb_strtolower(preg_replace('/[^a-z0-9&]+/i', ' ', $brand)) . ' ';

            if (str_contains($haystack, $needle)) {
                return $brand;
            }
        }

        return null;
    }
}
