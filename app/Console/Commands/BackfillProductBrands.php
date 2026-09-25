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

        $candidates = $this->candidateBrands();

        // Products with no brand at all, and products filed under the
        // placeholder brand - which since the default-brand migration is where
        // every unbranded product lives, so looking only for NULL found nothing.
        $defaultId = ProductBrand::defaultId();
        $products = Product::query()
            ->where('status', Status::ACTIVE)
            ->where(function ($query) use ($defaultId) {
                $query->whereNull('product_brand_id');

                if ($defaultId) {
                    $query->orWhere('product_brand_id', $defaultId);
                }
            })
            ->orderBy('id')
            ->get(['id', 'name', 'product_brand_id']);

        $matches = [];
        $unmatched = 0;

        foreach ($products as $product) {
            $key = $this->matchBrand((string) $product->name, $candidates);

            if ($key === null) {
                $unmatched++;
                continue;
            }

            $matches[] = ['id' => $product->id, 'name' => $product->name, 'key' => $key];
        }

        $this->info('Products without a real brand : ' . $products->count());
        $this->info('Matched to a brand            : ' . count($matches));
        $this->info('No brand found in the name    : ' . $unmatched);

        if (empty($matches)) {
            $this->warn('Nothing matched - no changes.');

            return self::SUCCESS;
        }

        // Per brand first: a wrong brand shows up here as one odd line, which
        // is far easier to spot than in a list of hundreds of products.
        $perBrand = collect($matches)->groupBy('key')->map(fn ($group, $key) => [
            $candidates[$key]['name'],
            $candidates[$key]['id'] ? 'existing' : 'NEW',
            $group->count(),
            mb_strimwidth((string) $group->first()['name'], 0, 60, '…'),
        ])->sortByDesc(2)->values()->all();

        $this->table(['Brand', 'Brand is', 'Products', 'Example product'], $perBrand);

        if ($dryRun) {
            $this->warn('Dry run - nothing was written. Re-run without --dry-run to apply.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($matches, $candidates) {
            $ids = [];

            foreach (collect($matches)->pluck('key')->unique() as $key) {
                $ids[$key] = $candidates[$key]['id'] ?? ProductBrand::firstOrCreate(
                    ['slug' => Str::slug($candidates[$key]['name'])],
                    ['name' => $candidates[$key]['name'], 'status' => Status::ACTIVE]
                )->id;
            }

            foreach ($matches as $match) {
                DB::table('products')->where('id', $match['id'])->update(['product_brand_id' => $ids[$match['key']]]);
            }
        });

        $this->info('Updated ' . count($matches) . ' products.');

        return self::SUCCESS;
    }

    /**
     * The brands a product may be matched to, keyed by normalised name and
     * longest first - so "Beauty of Joseon" wins over "Beauty Glazed" and
     * "Clean & Clear" is tried before anything shorter.
     *
     * Brands created in the admin panel are matched only at the START of a
     * product name, which is how these products are named ("MARS Matte
     * Mousse ..."): some of them are ordinary words ("image", "insight",
     * "centella") that appear inside other brands' product names. The curated
     * list below may also match anywhere, as it always could.
     *
     * @return array<string,array{name: string, id: ?int, anywhere: bool}>
     */
    private function candidateBrands(): array
    {
        $candidates = [];

        foreach (ProductBrand::query()->where('status', Status::ACTIVE)->storefront()->get(['id', 'name']) as $brand) {
            $key = $this->normalise((string) $brand->name);

            if ($key !== '' && !isset($candidates[$key])) {
                $candidates[$key] = ['name' => (string) $brand->name, 'id' => (int) $brand->id, 'anywhere' => false];
            }
        }

        foreach (self::BRANDS as $name) {
            $key = $this->normalise($name);

            if (isset($candidates[$key])) {
                $candidates[$key]['anywhere'] = true;
            } else {
                $candidates[$key] = ['name' => $name, 'id' => null, 'anywhere' => true];
            }
        }

        uksort($candidates, fn ($a, $b) => strlen(str_replace(' ', '', $b)) <=> strlen(str_replace(' ', '', $a)));

        return $candidates;
    }

    /**
     * @param  array<string,array{name: string, id: ?int, anywhere: bool}>  $candidates
     */
    private function matchBrand(string $productName, array $candidates): ?string
    {
        $words = array_values(array_filter(explode(' ', $this->normalise($productName)), 'strlen'));

        if (!$words) {
            return null;
        }

        // 1. At the start of the name, allowing for the brand's letters being
        //    spaced differently ("Deconstruct" for "de cons truct", "WishCare"
        //    for "Wish Care") - but only on whole words, so "bob" never
        //    claims "Bobbi Brown".
        foreach (array_keys($candidates) as $key) {
            $needle = str_replace(' ', '', $key);
            $joined = '';

            foreach ($words as $word) {
                $joined .= $word;

                if (strlen($joined) >= strlen($needle)) {
                    break;
                }
            }

            if ($joined === $needle) {
                return $key;
            }
        }

        // 2. Anywhere in the name, for the curated list only.
        $haystack = ' ' . implode(' ', $words) . ' ';

        foreach ($candidates as $key => $brand) {
            if ($brand['anywhere'] && str_contains($haystack, ' ' . $key . ' ')) {
                return $key;
            }
        }

        return null;
    }

    /** "POND'S", "Pond’s" and "ponds" all become "ponds"; "Care:Nel" becomes "care nel". */
    private function normalise(string $value): string
    {
        $value = mb_strtolower(str_replace(["'", '’', '`'], '', $value));
        $value = preg_replace('/[^a-z0-9&]+/', ' ', $value);

        return trim(preg_replace('/\s+/', ' ', $value));
    }
}
