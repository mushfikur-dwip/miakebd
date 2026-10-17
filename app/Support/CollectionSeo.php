<?php

namespace App\Support;

use App\Enums\Status;
use App\Models\Order;
use App\Models\Product;
use App\Models\Stock;
use Illuminate\Database\Eloquent\Builder;

/**
 * The words a brand or category page is read by - by Google, and by the
 * answer engines (ChatGPT, Perplexity, Google's AI Overviews) that quote a
 * page rather than rank it.
 *
 * WHY THIS EXISTS: those pages were a heading and a grid of product cards.
 * Nothing on them answered what a shopper actually asks - "how much is CeraVe
 * in Bangladesh", "is it original", "can I pay cash on delivery" - so there
 * was nothing for an answer engine to cite and little for Google to rank
 * beyond the title. Every answer here is built from live data (the real price
 * range, the real best sellers by units sold) or from claims the shop already
 * makes site-wide; nothing is invented per page.
 *
 * One builder feeds the crawler fallback, the FAQPage markup and the section
 * the shop page renders (via /api/frontend/collection-seo), so what the markup
 * claims is always what the page shows.
 */
class CollectionSeo
{
    public const BRAND = 'brand';
    public const CATEGORY = 'category';

    private const SOURCES = 'Malaysia, Thailand and Indonesia';
    private const OUTLETS = "Shop No. 52, Level 1, RAMC Shopping Complex, and Prime Medical College Gate, Badarganj Road";
    private const CITIES = 'Dhaka, Chittagong, Sylhet, Khulna, Rajshahi, Barisal and Rangpur';

    private const BEST_SELLERS = 3;
    private const NAMED_RELATED = 6;

    /**
     * Current selling prices of these products, lowest and highest.
     *
     * @return array{0: ?float, 1: ?float, 2: ?array, 3: ?array} min, max, and the cheapest / dearest product as [name, price]
     */
    public static function prices(Builder $products): array
    {
        $priced = (clone $products)
            ->with('variations:id,product_id')
            ->get(['id', 'name', 'selling_price', 'variation_price', 'discount', 'offer_start_date', 'offer_end_date'])
            ->map(fn (Product $product) => [
                'name'  => SeoSchema::cleanName($product->name),
                'price' => SeoSchema::pricing($product)['current'],
            ])
            ->filter(fn ($row) => $row['price'] > 0 && $row['name'] !== '')
            ->sortBy('price')
            ->values();

        if ($priced->isEmpty()) {
            return [null, null, null, null];
        }

        return [$priced->first()['price'], $priced->last()['price'], $priced->first(), $priced->last()];
    }

    /**
     * The products customers actually bought most, by units sold.
     *
     * A sale writes a negative stock row against the order; a reversal writes
     * it back, so the net is what really left. Only ACTIVE rows: an online
     * order still waiting for its payment has sold nothing yet.
     *
     * @return array<int,array{name: string, url: string}>
     */
    public static function bestSellers(Builder $products, int $limit = self::BEST_SELLERS): array
    {
        $sold = Stock::query()
            ->where('model_type', Order::class)
            ->where('status', Status::ACTIVE)
            ->whereIn('product_id', (clone $products)->select('products.id'))
            ->selectRaw('product_id, -SUM(quantity) as sold')
            ->groupBy('product_id')
            ->havingRaw('-SUM(quantity) > 0')
            ->orderByDesc('sold')
            ->orderBy('product_id')
            ->limit($limit)
            ->pluck('sold', 'product_id');

        if ($sold->isEmpty()) {
            return [];
        }

        $names = Product::whereIn('id', $sold->keys())->get(['id', 'name', 'slug'])->keyBy('id');

        return $sold->keys()
            ->map(fn ($id) => $names->get($id))
            ->filter()
            ->map(fn (Product $product) => [
                'name' => SeoSchema::cleanName($product->name),
                'url'  => self::siteUrl() . '/product/' . rawurlencode($product->slug),
            ])
            ->values()
            ->all();
    }

    public static function money(float $amount): string
    {
        return '৳' . number_format(round($amount), 0);
    }

    /** "৳1,350–৳5,430", or one figure when every product costs the same. */
    public static function range(?float $min, ?float $max): ?string
    {
        if ($min === null || $max === null) {
            return null;
        }

        return round($min) === round($max) ? self::money($min) : self::money($min) . '–' . self::money($max);
    }

    /**
     * The search-result description: the price first, because "price in
     * Bangladesh" is how shoppers here phrase the search and a figure in the
     * snippet is what earns the click.
     */
    public static function description(string $name, int $count, ?string $range): string
    {
        $offer = $count > 1
            ? "Shop {$count} authentic {$name} products at Suglow with cash on delivery."
            : "Shop authentic {$name} at Suglow with cash on delivery.";

        return SeoSchema::limit(
            ($range ? "{$name} price in Bangladesh: {$range}. " : '') . $offer . ' Call ' . SiteSchema::PHONE_TEXT . '.',
            158
        );
    }

    /** Admin-written text split into paragraphs on blank lines. */
    public static function paragraphs(?string $text): array
    {
        $text = trim(str_replace("\r\n", "\n", (string) $text));

        if ($text === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($block) => SeoSchema::plainText($block),
            preg_split('/\n\s*\n/', $text)
        ), fn ($block) => $block !== ''));
    }

    /**
     * Intro, questions and related links for one page.
     *
     * @param  array{
     *     kind: string, name: string, count: int,
     *     min: ?float, max: ?float, cheapest: ?array, dearest: ?array,
     *     best_sellers: array, related: array, own_text: ?string, admin_text: ?string
     * }  $facts
     * @return array{heading: string, intro: string[], faqs: array, related_heading: string, related: array}
     */
    public static function content(array $facts): array
    {
        $name     = $facts['name'];
        $isBrand  = $facts['kind'] === self::BRAND;
        $count    = (int) $facts['count'];
        $range    = self::range($facts['min'], $facts['max']);
        $related  = $facts['related'];

        $intro = self::paragraphs($facts['admin_text'] ?? null);

        if ($intro === []) {
            $own = SeoSchema::plainText($facts['own_text'] ?? null);
            if (mb_strlen($own) > 60) {
                $intro[] = $own;
            }
            if ($count > 0) {
                $intro[] = self::generatedIntro($name, $isBrand, $count, $range, $related);
            }
        }

        return [
            'heading'         => "About {$name} in Bangladesh",
            'intro'           => $intro,
            'faqs'            => $count > 0 ? self::faqs($facts, $range) : [],
            'related_heading' => $isBrand ? "Shop {$name} by category" : "{$name} brands at Suglow",
            'related'         => $related,
        ];
    }

    private static function generatedIntro(string $name, bool $isBrand, int $count, ?string $range, array $related): string
    {
        $what  = $count > 1 ? "{$count} original {$name} products" : "original {$name}";
        $price = $range ? (str_contains($range, '–') ? ', priced from ' . str_replace('–', ' to ', $range) : ", at {$range}") : '';

        // A category names the brands it carries: "from CeraVe, Anua and
        // Beauty of Joseon", or "from 12 brands including ..." past three.
        $brands = '';
        if (!$isBrand && $related !== []) {
            $brands = count($related) > 3
                ? ', from ' . count($related) . ' brands including ' . self::listNames(array_slice($related, 0, 3))
                : ', from ' . self::listNames($related);
        }

        return "Suglow sells {$what} in Bangladesh{$price}{$brands}. "
            . 'Everything is imported directly from ' . self::SOURCES . ' and can be ordered online with cash on delivery anywhere in the country, '
            . 'or bought in person at our two outlets in Rangpur.';
    }

    /** @return array<int,array{question: string, answer: string}> */
    private static function faqs(array $facts, ?string $range): array
    {
        $name    = $facts['name'];
        $isBrand = $facts['kind'] === self::BRAND;
        $count   = (int) $facts['count'];
        $faqs    = [];

        if ($range) {
            $question = $isBrand ? "What is the price of {$name} in Bangladesh?" : "How much do {$name} products cost in Bangladesh?";

            if ($count > 1 && $facts['cheapest'] && $facts['dearest'] && $facts['cheapest']['name'] !== $facts['dearest']['name']) {
                $answer = "{$name} products at Suglow cost from " . str_replace('–', ' to ', $range) . '. '
                    . "{$facts['cheapest']['name']} is " . self::money($facts['cheapest']['price'])
                    . " and {$facts['dearest']['name']} is " . self::money($facts['dearest']['price']) . '. '
                    . 'The prices on suglow.com are always the current ones.';
            } else {
                $item   = $facts['cheapest']['name'] ?? $name;
                $answer = "{$item} is {$range} at Suglow. The prices on suglow.com are always the current ones.";
            }

            $faqs[] = ['question' => $question, 'answer' => $answer];
        }

        if ($facts['best_sellers'] !== []) {
            $faqs[] = [
                'question' => "Which {$name} products sell best at Suglow?",
                'answer'   => count($facts['best_sellers']) > 1
                    ? "The {$name} products Suglow customers order most are " . self::listNames($facts['best_sellers']) . '.'
                    : "The {$name} product Suglow customers order most is {$facts['best_sellers'][0]['name']}.",
            ];
        }

        if (!$isBrand && $facts['related'] !== []) {
            $named = array_slice($facts['related'], 0, self::NAMED_RELATED);
            $more  = count($facts['related']) - count($named);

            $faqs[] = [
                'question' => "Which {$name} brands does Suglow sell?",
                'answer'   => "Suglow sells {$name} from " . self::listNames($named)
                    . ($more > 0 ? ", plus {$more} more " . ($more === 1 ? 'brand' : 'brands') : '') . '.',
            ];
        }

        $faqs[] = $isBrand
            ? [
                'question' => "Is {$name} at Suglow original?",
                'answer'   => "Yes. Suglow sells only 100% authentic {$name} products, imported directly from " . self::SOURCES . ' through its own warehouse in Kuala Lumpur.',
            ]
            : [
                'question' => "Are the {$name} products at Suglow authentic?",
                'answer'   => "Yes. Every {$name} product at Suglow is 100% authentic, imported directly from " . self::SOURCES . ' through its own warehouse in Kuala Lumpur.',
            ];

        $faqs[] = [
            'question' => "Can I buy {$name} with cash on delivery in Bangladesh?",
            'answer'   => "Yes. Order {$name} at suglow.com and pay in cash when it arrives. Suglow delivers nationwide, including " . self::CITIES . '.',
        ];

        $faqs[] = [
            'question' => "Where can I buy {$name} in Rangpur?",
            'answer'   => "At Suglow's two outlets in Rangpur City: " . self::OUTLETS . '. Call ' . SiteSchema::PHONE_TEXT . ' (24/7) to check what is in stock.',
        ];

        return $faqs;
    }

    /** FAQPage node for a page's questions, or null when it has none. */
    public static function faqNode(string $pageUrl, array $faqs): ?array
    {
        if ($faqs === []) {
            return null;
        }

        return [
            '@type'      => 'FAQPage',
            '@id'        => $pageUrl . '#faq',
            'url'        => $pageUrl,
            'mainEntity' => array_map(fn ($faq) => [
                '@type'          => 'Question',
                'name'           => $faq['question'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $faq['answer']],
            ], $faqs),
        ];
    }

    /** "A", "A and B", "A, B and C". */
    private static function listNames(array $items): string
    {
        $names = array_column($items, 'name');
        $last  = array_pop($names);

        return $names === [] ? (string) $last : implode(', ', $names) . ' and ' . $last;
    }

    private static function siteUrl(): string
    {
        return rtrim((string) config('app.url'), '/');
    }
}
