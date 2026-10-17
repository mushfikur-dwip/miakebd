<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Support\BrandMetaResolver;
use App\Support\CategoryMetaResolver;
use App\Support\CollectionSeo;
use Illuminate\Http\JsonResponse;

/**
 * A brand or category page's intro, questions and related links, for the shop
 * page to show under its products.
 *
 * The same words the server already put in the crawler fallback and the
 * FAQPage markup: Google renders the page with JavaScript and reads what the
 * shopper sees, and its guidelines require marked-up questions to be visible.
 * Also the page's title and description, so moving between listings inside
 * the shop updates the browser tab instead of keeping the previous page's.
 */
class CollectionSeoController extends Controller
{
    public function show(string $type, string $slug): JsonResponse
    {
        $meta = match ($type) {
            CollectionSeo::BRAND    => BrandMetaResolver::forSlug($slug),
            CollectionSeo::CATEGORY => CategoryMetaResolver::forSlug($slug),
            default                 => null,
        };

        if ($meta === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $content = $meta['content'] ?? [];
        $siteUrl = rtrim((string) config('app.url'), '/');

        return response()->json(['data' => [
            'name'            => $meta['name'],
            // As the server-rendered page titles it: the shop's name once.
            'title'           => mb_stripos($meta['title'], 'suglow') !== false ? $meta['title'] : $meta['title'] . ' | Suglow',
            'description'     => $meta['description'],
            'heading'         => $content['heading'] ?? '',
            'intro'           => $content['intro'] ?? [],
            'faqs'            => $content['faqs'] ?? [],
            'related_heading' => $content['related_heading'] ?? '',
            // Site-relative, so the shop page can route to them without a reload.
            'related'         => array_map(fn ($link) => [
                'name'  => $link['name'],
                'path'  => str_starts_with($link['url'], $siteUrl) ? substr($link['url'], strlen($siteUrl)) : $link['url'],
                'count' => $link['count'],
            ], $content['related'] ?? []),
        ]]);
    }
}
