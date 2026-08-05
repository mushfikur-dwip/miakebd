<?php

namespace App\Support;

/**
 * Preload/modulepreload hints for the built Vite entry, read from
 * public/build/manifest.json at render time.
 *
 * WHY: hashed asset names change every build, so the links cannot be
 * hardcoded in master.blade.php. The @vite directive only tags the entry
 * itself; the browser then discovers the entry's static imports (the vendor
 * chunk) one request later. modulepreload removes that waterfall round-trip
 * on a cold first visit.
 *
 * SAFE: a missing or unreadable manifest (e.g. before the first build)
 * yields an empty list and the page renders exactly as before. Resolved once
 * per request and cached in a static.
 */
class ViteAssets
{
    private const ENTRY = 'resources/js/app.js';

    /**
     * @return array<int,array{type:string,href:string}>
     */
    public static function preloads(): array
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        $cache = [];

        try {
            $manifestPath = public_path('build/manifest.json');

            if (!is_file($manifestPath)) {
                return $cache;
            }

            $manifest = json_decode((string) file_get_contents($manifestPath), true);

            if (!is_array($manifest)) {
                return $cache;
            }

            $entry = $manifest[self::ENTRY] ?? null;

            if (!is_array($entry)) {
                return $cache;
            }

            foreach ((array) ($entry['css'] ?? []) as $css) {
                if (is_string($css) && $css !== '') {
                    $cache[] = ['type' => 'style', 'href' => asset('build/' . ltrim($css, '/'))];
                }
            }

            // The entry's static imports first (vendor chunk etc.), then the
            // entry itself.
            $scripts = [];

            foreach ((array) ($entry['imports'] ?? []) as $import) {
                $file = $manifest[$import]['file'] ?? null;

                if (is_string($file) && $file !== '') {
                    $scripts[] = $file;
                }
            }

            if (!empty($entry['file']) && is_string($entry['file'])) {
                $scripts[] = $entry['file'];
            }

            foreach (array_unique($scripts) as $script) {
                $cache[] = ['type' => 'script', 'href' => asset('build/' . ltrim($script, '/'))];
            }
        } catch (\Throwable $e) {
            $cache = [];
        }

        return $cache;
    }
}
