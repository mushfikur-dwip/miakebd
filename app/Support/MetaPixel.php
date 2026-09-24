<?php

namespace App\Support;

use Dipokhalder\Settings\Facades\Settings;
use Illuminate\Support\Collection;

/**
 * Where the Meta (Facebook) Pixel id comes from, and whether this page still
 * needs to load the base code.
 *
 * The id can be configured three ways, because a shop can reasonably have done
 * any of them already:
 *   1. META_PIXEL_ID in .env          - fastest to set on the server
 *   2. a `site_meta_pixel_id` setting - if an admin field is added later
 *   3. the snippet pasted into Admin -> Analytics, which is the screen built
 *      for exactly this; the id is read back out of it
 *
 * The third case also tells us the base code is already on the page, so the
 * shell must not print a second copy: two `fbq('init')` calls for one id send
 * every PageView twice and inflate every number in Events Manager.
 */
class MetaPixel
{
    /** Meta ids are numeric and 15-16 digits today; the range is deliberately loose. */
    private const ID_PATTERN = '/^\d{10,20}$/';

    /**
     * @param  Collection|iterable|null  $analytics  Analytic models with their sections, as the shell already loads them.
     * @return array{id: ?string, render_base: bool}
     */
    public static function resolve($analytics = null): array
    {
        $fromSnippet = self::idFromAnalytics($analytics);
        $configured  = self::configuredId();

        $id = $configured ?: $fromSnippet;

        return [
            'id' => $id,
            // Only when nothing on the page loads it already.
            'render_base' => $id !== null && $fromSnippet === null,
            // Every value sent to Meta needs a currency beside it, and the
            // storefront settings only carry the symbol.
            'currency' => self::currencyCode(),
            // 'id' or 'sku' - whichever the Facebook catalogue feed is keyed
            // on. Shared with the browser so its content_ids and the server's
            // cannot disagree.
            'content_id' => config('services.meta_pixel.content_id') === 'sku' ? 'sku' : 'id',
        ];
    }

    private static function currencyCode(): string
    {
        try {
            $currency = \App\Models\Currency::find(Settings::group('site')->get('site_default_currency'));

            if ($currency && !blank($currency->code)) {
                return strtoupper($currency->code);
            }
        } catch (\Throwable $e) {
            // Falls through to the env default below.
        }

        return strtoupper((string) env('CURRENCY', 'BDT')) ?: 'BDT';
    }

    /** The id from .env or settings - not from a pasted snippet. */
    public static function configuredId(): ?string
    {
        $id = trim((string) config('services.meta_pixel.id'));

        if ($id === '') {
            // A key the settings table may not have; the store returns null then.
            $id = trim((string) Settings::group('site')->get('site_meta_pixel_id'));
        }

        return preg_match(self::ID_PATTERN, $id) ? $id : null;
    }

    /**
     * Reads the id out of whatever the shop pasted into Analytics.
     *
     * Matches the `fbq('init', '123...')` line of Meta's own snippet, which is
     * the one part of it that has not changed in years. A GTM container or any
     * other tag manager simply will not match, and then the pixel is left
     * entirely to that tag manager.
     */
    private static function idFromAnalytics($analytics): ?string
    {
        foreach ($analytics ?? [] as $analytic) {
            foreach ($analytic->analyticSections ?? [] as $section) {
                if (preg_match('/fbq\s*\(\s*[\'"]init[\'"]\s*,\s*[\'"](\d{10,20})[\'"]/', (string) $section->data, $matches)) {
                    return $matches[1];
                }
            }
        }

        return null;
    }
}
