<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Streams a file from storage/app/public for GET /storage/{path}.
 *
 * On this host the public/storage symlink cannot be used (LiteSpeed will not
 * follow it, and zip deploys delete it), so PHP serves every uploaded file.
 * Two routes do it - one in routes/web.php and a later one registered by
 * AppServiceProvider so it outranks the framework's own storage.local route -
 * and both call this, so they cannot drift apart.
 */
class PublicStorageFile
{
    /**
     * Media types this route may hand out, judged by the file's bytes.
     *
     * This disk also holds files that must never be public - the Firebase
     * service-account JSON sits at a guessable /storage/{media id}/ path - and
     * an uploaded .html served from this origin runs with full access to the
     * admin's token in localStorage.
     */
    private const SERVABLE = [
        'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/bmp', 'image/avif',
        'image/x-icon', 'image/vnd.microsoft.icon', 'image/svg+xml',
        'application/pdf',
        'audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/x-wav', 'audio/ogg',
        'video/mp4', 'video/webm',
    ];

    public static function respond(string $path): BinaryFileResponse
    {
        $base = realpath(storage_path('app/public'));
        $file = $base === false ? false : realpath($base . DIRECTORY_SEPARATOR . $path);

        // realpath() has already collapsed any ../ segments, so this prefix test
        // is what keeps a crafted path from escaping the disk root.
        if ($file === false || !str_starts_with($file, $base . DIRECTORY_SEPARATOR) || !is_file($file)) {
            abort(404);
        }

        $mime = (string) (@mime_content_type($file) ?: '');
        if (!in_array($mime, self::SERVABLE, true)) {
            abort(404);
        }

        // A replaced image gets a new media id and therefore a new URL, so these
        // are immutable. Long caching lets the CDN absorb the load rather than
        // asking PHP for every thumbnail on every page.
        $headers = [
            'Content-Type'  => $mime,
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ];

        // Opened directly, an SVG is a document that can carry script. The
        // sandbox gives it an opaque origin with scripts off, so even a hostile
        // file cannot reach this site's storage. PDFs are left out only because
        // Chrome's viewer refuses to render inside a sandbox; a PDF's own script
        // runs in the viewer, never in this origin.
        if ($mime !== 'application/pdf') {
            $headers['Content-Security-Policy'] = "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; media-src 'self'; sandbox";
        }

        return response()->file($file, $headers);
    }
}
