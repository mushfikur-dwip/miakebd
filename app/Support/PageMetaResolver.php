<?php

namespace App\Support;

use App\Enums\Status;
use App\Models\Page;

/**
 * Server-side metadata for CMS pages (/page/{slug}): About us, Support, Legal.
 *
 * These are the pages answer engines and Google read to decide whether a shop
 * is real - who runs it, how to reach it, what the terms are - and until now
 * crawlers got the generic site title and description on every one of them,
 * because Vue only rendered the content after JavaScript ran.
 */
class PageMetaResolver
{
    private const DESCRIPTION_LIMIT = 155;

    /**
     * Metadata for an active page, or null when there is no such page or it is
     * switched off (the caller answers 404).
     */
    public static function forSlug(string $slug): ?array
    {
        $page = Page::query()->where('slug', $slug)->where('status', Status::ACTIVE)->first();

        if (!$page) {
            return null;
        }

        $siteUrl = rtrim((string) config('app.url'), '/');
        $name    = trim((string) $page->title) ?: 'Suglow';
        $body    = (string) $page->description;

        return [
            'name'        => $name,
            'title'       => $name . ' | Suglow',
            'description' => self::description($body) ?: $name . ' — Suglow, authentic cosmetics and skincare in Bangladesh.',
            'url'         => $siteUrl . '/page/' . rawurlencode($page->slug),
            // schema.org page type: what the page is about, read off its address.
            'type'        => str_contains($page->slug, 'about')
                ? 'AboutPage'
                : (str_contains($page->slug, 'contact') || str_contains($page->slug, 'support') ? 'ContactPage' : 'WebPage'),
            // Admin-authored HTML from the page editor, printed raw in the
            // noscript block - the same trust level as blog bodies.
            'body'        => $body,
        ];
    }

    /** The typed page plus its breadcrumb, tied to the site-wide entities. */
    public static function structuredData(array $meta): array
    {
        $siteUrl = rtrim((string) config('app.url'), '/');

        $page = [
            '@type'       => $meta['type'],
            '@id'         => $meta['url'] . '#webpage',
            'url'         => $meta['url'],
            'name'        => $meta['name'],
            'description' => $meta['description'],
            'inLanguage'  => 'en',
            'isPartOf'    => ['@id' => $siteUrl . '/#website'],
            'publisher'   => ['@id' => $siteUrl . '/#organization'],
            'breadcrumb'  => ['@id' => $meta['url'] . '#breadcrumb'],
        ];

        // An About or Contact page is about the shop itself.
        if ($meta['type'] !== 'WebPage') {
            $page['about'] = ['@id' => $siteUrl . '/#organization'];
        }

        return ['@context' => 'https://schema.org', '@graph' => [
            $page,
            [
                '@type'           => 'BreadcrumbList',
                '@id'             => $meta['url'] . '#breadcrumb',
                'itemListElement' => [
                    ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => $siteUrl . '/'],
                    ['@type' => 'ListItem', 'position' => 2, 'name' => $meta['name'], 'item' => $meta['url']],
                ],
            ],
        ]];
    }

    /**
     * The page's own words as a meta description: tags become spaces (so
     * "<h2>Terms</h2><p>Orders" does not run together), whitespace collapses,
     * and a long text is cut at a word with an ellipsis.
     */
    private static function description(string $html): string
    {
        $text = html_entity_decode(preg_replace('/<[^>]*>/', ' ', $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $text)));

        if (mb_strlen($text) <= self::DESCRIPTION_LIMIT) {
            return $text;
        }

        $cut = mb_substr($text, 0, self::DESCRIPTION_LIMIT - 1);
        $cut = rtrim(mb_substr($cut, 0, (int) mb_strrpos($cut, ' ')), " ,;:-–—");

        return preg_match('/[.!?]$/u', $cut) ? $cut : $cut . '…';
    }
}
