<?php

namespace App\Support;

/**
 * Where the TikTok Pixel id comes from, and whether this page still needs to
 * load the base code.
 *
 * The same two sources as App\Support\MetaPixel: TIKTOK_PIXEL_ID in .env, or
 * TikTok's snippet pasted into Admin -> Analytics. In the second case the
 * snippet already loads the pixel, so the shell must not print another copy:
 * two `ttq.load()` calls for one id count every visit twice.
 */
class TikTokPixel
{
    /** TikTok pixel codes are 20 upper-case letters and digits; the range is deliberately loose. */
    private const ID_PATTERN = '/^[A-Z0-9]{15,30}$/';

    /**
     * @param  \Illuminate\Support\Collection|iterable|null  $analytics  Analytic models with their sections, as the shell already loads them.
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
        ];
    }

    public static function configuredId(): ?string
    {
        $id = strtoupper(trim((string) config('services.tiktok_pixel.id')));

        return preg_match(self::ID_PATTERN, $id) ? $id : null;
    }

    /** Matches the `ttq.load('...')` line of TikTok's own snippet. */
    private static function idFromAnalytics($analytics): ?string
    {
        foreach ($analytics ?? [] as $analytic) {
            foreach ($analytic->analyticSections ?? [] as $section) {
                if (preg_match('/ttq\.load\(\s*[\'"]([A-Z0-9]{15,30})[\'"]/', (string) $section->data, $matches)) {
                    return $matches[1];
                }
            }
        }

        return null;
    }
}
