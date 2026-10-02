<?php

namespace App\Support;

use App\Enums\Status;
use App\Models\Product;
use Dipokhalder\Settings\Facades\Settings;
use Illuminate\Support\Facades\Cache;

/**
 * Site-wide JSON-LD graph for master.blade.php.
 *
 * Emitted on EVERY page: WebSite (with the SearchAction that backs the
 * sitelinks search box) and the Organization entity, both keyed by stable
 * @ids so Google merges them with the richer homepage declaration instead of
 * treating them as duplicates. The homepage additionally gets the two
 * physical Store entities and the FAQPage — those belong to one page, not
 * the whole site.
 *
 * The graph content used to be typed inline in master.blade.php; it lives
 * here now so the blade file stays readable and the phone/address facts have
 * a single source of truth (the noscript block reads the same constants).
 */
class SiteSchema
{
    public const PHONE = '+8801709786330';
    public const PHONE_TEXT = '01709786330';

    /**
     * The shop's social profiles, from Admin -> Settings -> Social Media.
     * sameAs is how Google's Knowledge Graph and AI answer engines tie the
     * Facebook page and YouTube channel to the same business as the website.
     * Only absolute http(s) URLs; one get() per key (a reused group handle
     * reads null for the second key).
     *
     * @return list<string>
     */
    public static function sameAs(): array
    {
        $urls = [];

        foreach (['social_media_facebook', 'social_media_instagram', 'social_media_twitter', 'social_media_youtube'] as $key) {
            try {
                $value = trim((string) Settings::group('social_media')->get($key));
            } catch (\Throwable $e) {
                $value = '';
            }

            if (preg_match('#^https?://\S+$#i', $value)) {
                $urls[] = $value;
            }
        }

        return $urls;
    }

    /**
     * "over 1,100 products" - counted, not typed. The FAQ used to say "over 440"
     * long after the catalogue passed a thousand, contradicting the sitemap and
     * llms.txt. Rounded down to the hundred so the wording only changes when
     * the catalogue really moves; cached because every homepage view reads it.
     */
    public static function productCountPhrase(): string
    {
        try {
            $count = (int) Cache::remember('seo:product-count', now()->addHours(6), fn () => Product::query()
                ->where('status', Status::ACTIVE)
                ->storefront()
                ->count());
        } catch (\Throwable $e) {
            $count = 0;
        }

        if ($count < 1) {
            // Unknown (or a fresh install): say nothing that could be wrong.
            return 'hundreds of products';
        }

        return $count < 100
            ? $count . ' products'
            : 'over ' . number_format(intdiv($count, 100) * 100) . ' products';
    }

    /**
     * @param  bool   $full       true on the homepage — adds stores and FAQ.
     * @param  string $brandImage absolute URL of the 1200x630 social card.
     * @param  string $brandLogo  absolute URL of the admin-uploaded logo.
     * @return array<string,mixed>
     */
    public static function graph(bool $full, string $brandImage, string $brandLogo): array
    {
        // config('app.url'), not url(): the same host-pinning trap documented in
        // CategoryMetaResolver::siteUrl() applies to every URL in this graph.
        $siteUrl = rtrim((string) config('app.url'), '/');

        $graph = [
            self::website($siteUrl),
            self::organization($siteUrl, $brandImage, $brandLogo, $full),
        ];

        if ($full) {
            $graph[] = self::store(
                $siteUrl,
                $brandImage,
                'ramc',
                'Suglow — RAMC Shopping Complex Outlet',
                'Shop No. 52, Level 1, RAMC Shopping Complex'
            );
            $graph[] = self::store(
                $siteUrl,
                $brandImage,
                'prime',
                'Suglow — Prime Medical College Gate Outlet',
                'Prime Medical College Gate, Badarganj Road'
            );
            $graph[] = self::faq($siteUrl);
        }

        return ['@context' => 'https://schema.org', '@graph' => $graph];
    }

    /**
     * WebSite with a SearchAction. The target is the SPA's real search URL —
     * FrontendNavBarComponent routes a search to /product?name={term}, and the
     * /product server route renders the listing shell for it.
     *
     * @return array<string,mixed>
     */
    private static function website(string $siteUrl): array
    {
        return [
            '@type' => 'WebSite',
            '@id' => $siteUrl . '/#website',
            'url' => $siteUrl . '/',
            'name' => 'Suglow',
            'inLanguage' => 'en',
            'publisher' => ['@id' => $siteUrl . '/#organization'],
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => [
                    '@type' => 'EntryPoint',
                    'urlTemplate' => $siteUrl . '/product?name={search_term_string}',
                ],
                'query-input' => 'required name=search_term_string',
            ],
        ];
    }

    /**
     * The business. Non-homepage pages get the slim form (same @id, so it
     * merges with the homepage declaration); the homepage gets every field.
     *
     * @return array<string,mixed>
     */
    private static function organization(string $siteUrl, string $brandImage, string $brandLogo, bool $full): array
    {
        $organization = [
            '@type' => ['Organization', 'OnlineStore'],
            '@id' => $siteUrl . '/#organization',
            'name' => 'Suglow',
            'url' => $siteUrl . '/',
            'logo' => [
                '@type' => 'ImageObject',
                'url' => $brandLogo,
            ],
        ];

        if (!$full) {
            return $organization;
        }

        if ($profiles = self::sameAs()) {
            $organization['sameAs'] = $profiles;
        }

        return $organization + [
            'alternateName' => ['Suglow BD', 'Suglow Bangladesh'],
            'image' => $brandImage,
            'description' => "Bangladesh's largest authentic cosmetics and skincare retailer. Products imported directly from Malaysia, Thailand and Indonesia. Online delivery nationwide across Bangladesh, plus two physical outlets in Rangpur. Customer service available 24/7.",
            'slogan' => 'Authentic cosmetics, delivered anywhere in Bangladesh',
            'telephone' => self::PHONE,
            'currenciesAccepted' => 'BDT',
            'paymentAccepted' => 'Cash on Delivery, Online Payment',
            'areaServed' => ['@type' => 'Country', 'name' => 'Bangladesh'],
            'contactPoint' => [
                '@type' => 'ContactPoint',
                'telephone' => self::PHONE,
                'contactType' => 'customer service',
                'areaServed' => 'BD',
                'availableLanguage' => ['Bengali', 'English'],
                'hoursAvailable' => [
                    '@type' => 'OpeningHoursSpecification',
                    'dayOfWeek' => ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'],
                    'opens' => '00:00',
                    'closes' => '23:59',
                ],
            ],
            'hasOfferCatalog' => [
                '@type' => 'OfferCatalog',
                'name' => 'Cosmetics and Skincare',
                'itemListElement' => [
                    ['@type' => 'OfferCatalog', 'name' => 'Skin Care'],
                    ['@type' => 'OfferCatalog', 'name' => 'Personal Care'],
                    ['@type' => 'OfferCatalog', 'name' => 'Fragrance'],
                    ['@type' => 'OfferCatalog', 'name' => 'Hair Care'],
                    ['@type' => 'OfferCatalog', 'name' => 'Sunscreen'],
                    ['@type' => 'OfferCatalog', 'name' => 'Baby Care'],
                    ['@type' => 'OfferCatalog', 'name' => 'Moisturizer'],
                    ['@type' => 'OfferCatalog', 'name' => "Men's Skin Care"],
                    ['@type' => 'OfferCatalog', 'name' => 'Accessories'],
                ],
            ],
        ];
    }

    /**
     * One physical outlet. Emitted on the homepage only — declaring both
     * stores on every page would repeat local data Google only needs once.
     *
     * @return array<string,mixed>
     */
    private static function store(string $siteUrl, string $brandImage, string $id, string $name, string $streetAddress): array
    {
        return [
            '@type' => ['Store', 'HealthAndBeautyBusiness'],
            '@id' => $siteUrl . '/#store-' . $id,
            'name' => $name,
            'parentOrganization' => ['@id' => $siteUrl . '/#organization'],
            'url' => $siteUrl . '/',
            'image' => $brandImage,
            'telephone' => self::PHONE,
            'currenciesAccepted' => 'BDT',
            'priceRange' => 'BDT 100 - BDT 5000',
            'paymentAccepted' => 'Cash, Cash on Delivery, Online Payment',
            'areaServed' => ['@type' => 'Country', 'name' => 'Bangladesh'],
            'address' => [
                '@type' => 'PostalAddress',
                'streetAddress' => $streetAddress,
                'addressLocality' => 'Rangpur',
                'addressRegion' => 'Rangpur Division',
                'addressCountry' => 'BD',
            ],
            'openingHoursSpecification' => [
                '@type' => 'OpeningHoursSpecification',
                'dayOfWeek' => ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'],
                'opens' => '00:00',
                'closes' => '23:59',
            ],
        ];
    }

    /**
     * FAQ for AEO. Every answer is also rendered as visible text in the
     * <noscript> block — Google requires FAQ markup to match content the
     * visitor can actually see.
     *
     * @return array<string,mixed>
     */
    private static function faq(string $siteUrl): array
    {
        return [
            '@type' => 'FAQPage',
            '@id' => $siteUrl . '/#faq',
            'mainEntity' => [
                [
                    '@type' => 'Question',
                    'name' => 'Where does Suglow import its cosmetics from?',
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => 'Suglow imports cosmetics and skincare products directly from Malaysia, Thailand and Indonesia, operating its own warehouse in Kuala Lumpur. Direct importing is how Suglow keeps products authentic and prices competitive in Bangladesh.',
                    ],
                ],
                [
                    '@type' => 'Question',
                    'name' => 'Does Suglow deliver across all of Bangladesh?',
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => 'Yes. Suglow delivers nationwide across Bangladesh, including Dhaka, Chittagong, Rangpur, Sylhet, Khulna, Rajshahi and Barisal. Cash on delivery is available on orders anywhere in the country.',
                    ],
                ],
                [
                    '@type' => 'Question',
                    'name' => 'Does Suglow have physical stores?',
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => 'Yes, Suglow has two outlets in Rangpur City: Shop No. 52, Level 1, RAMC Shopping Complex, and one at Prime Medical College Gate on Badarganj Road. Customers anywhere else in Bangladesh can order online at suglow.com.',
                    ],
                ],
                [
                    '@type' => 'Question',
                    'name' => 'Is cash on delivery available at Suglow?',
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => 'Yes. Suglow accepts cash on delivery on orders across Bangladesh, so customers pay only when the product reaches them. Online payment is also accepted.',
                    ],
                ],
                [
                    '@type' => 'Question',
                    'name' => 'How do I contact Suglow?',
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => 'Suglow customer service is available 24/7 on ' . self::PHONE_TEXT . '. Support is offered in both Bengali and English.',
                    ],
                ],
                [
                    '@type' => 'Question',
                    'name' => 'What products does Suglow sell?',
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => 'Suglow stocks ' . self::productCountPhrase() . ' across skin care, personal care, fragrance, hair care, sunscreen, baby care, moisturizers, mens skin care and beauty accessories, from brands including Nivea, Dove, Garnier, Vaseline, CeraVe, Fogg, Lotus, Enchanteur, Bioaqua and Sadoer.',
                    ],
                ],
            ],
        ];
    }
}
