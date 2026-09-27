<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Turns whatever an admin pastes for a Facebook video into the one address
 * Facebook's embed player accepts: https://www.facebook.com/reel/{id}/.
 *
 * Since September 2025 every Facebook video is a reel, and the link most
 * people copy - Share, then Copy link - is a /share/r/... redirect the player
 * cannot follow: it shows "Video unavailable". The player is happy with any
 * address that carries the video's id (/reel/{id}, /{page}/videos/{id},
 * watch?v={id}), which all end up at the same /reel/{id}/.
 */
class FacebookVideoLink
{
    private const BROWSER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';

    // Facebook answers its own link-preview crawler with the video's
    // canonical address, even for a share link.
    private const CRAWLER_AGENT = 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)';

    /**
     * The video address inside what was pasted: Facebook's Embed code (an
     * <iframe> of its player, or the SDK's <div class="fb-video">), a player
     * address, or a plain link. String work only.
     */
    public static function fromPasted(string $input): string
    {
        $input = trim($input);

        if (preg_match('~src=["\']([^"\']*facebook\.com/(?:v[\d.]+/)?plugins/video\.php[^"\']*)["\']~i', $input, $match)) {
            $input = html_entity_decode($match[1]);
        } elseif (preg_match('~data-href=["\']([^"\']+)["\']~i', $input, $match)) {
            $input = html_entity_decode($match[1]);
        }

        if (preg_match('~facebook\.com/(?:v[\d.]+/)?plugins/video\.php~i', $input)) {
            parse_str((string) parse_url($input, PHP_URL_QUERY), $query);
            if (!empty($query['href']) && is_string($query['href'])) {
                $input = $query['href'];
            }
        }

        return trim($input);
    }

    /** The video id from any address that carries one, else null. */
    public static function videoId(string $url): ?string
    {
        $pattern = '~facebook\.com/(?:'
            . 'reels?/'                                  // /reel/{id}
            . '|[^/?#]+/videos/(?:[^/?#]+/)*'            // /{page}/videos/[title/]{id}
            . '|watch/?\?(?:[^#]*&)?v='                  // /watch/?v={id}
            . '|video\.php\?(?:[^#]*&)?v='               // /video.php?v={id}
            . ')(\d{6,})~i';

        return preg_match($pattern, $url, $match) ? $match[1] : null;
    }

    public static function canonical(string $id): string
    {
        return 'https://www.facebook.com/reel/' . $id . '/';
    }

    /** A link that only says where to go - it has to be followed to find the video. */
    public static function isShortLink(string $url): bool
    {
        return (bool) preg_match('~facebook\.com/share/|fb\.watch/|//(?:www\.)?fb\.com/~i', $url);
    }

    /**
     * Follows a share or fb.watch link to the video it points at. Redirects
     * are followed by hand, so every hop's address is looked at, and the
     * page's og:url / canonical address is read at the end. Null when none of
     * them names a video.
     */
    public static function resolve(string $url): ?string
    {
        try {
            for ($hop = 0; $hop < 6; $hop++) {
                if ($id = self::videoId($url)) {
                    return self::canonical($id);
                }

                $response = Http::withHeaders(['User-Agent' => self::CRAWLER_AGENT, 'Accept-Language' => 'en'])
                    ->withoutRedirecting()
                    ->timeout(8)
                    ->get($url);

                $location = $response->header('Location');
                if ($response->redirect() && $location !== '') {
                    $url = str_starts_with($location, '/') ? 'https://www.facebook.com' . $location : $location;
                    continue;
                }

                $body = $response->body();
                foreach (['~<meta property="og:url" content="([^"]+)"~i', '~<link rel="canonical" href="([^"]+)"~i'] as $pattern) {
                    if (preg_match($pattern, $body, $match) && ($id = self::videoId(html_entity_decode($match[1])))) {
                        return self::canonical($id);
                    }
                }

                return null;
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * Will Facebook's player play this on another website? It only does for a
     * reel whose audience is Public. True or false from what the player's page
     * holds - the video element and its source when it will play - and null
     * when Facebook could not be asked at all.
     */
    public static function playable(string $url): ?bool
    {
        try {
            $response = Http::withHeaders(['User-Agent' => self::BROWSER_AGENT, 'Accept-Language' => 'en'])
                ->timeout(8)
                ->get('https://www.facebook.com/plugins/video.php', ['href' => $url, 'show_text' => 'false']);

            if (!$response->successful()) {
                return null;
            }

            return (bool) preg_match('~<video\b|hd_src|sd_src|playable_url~i', $response->body());
        } catch (Throwable) {
            return null;
        }
    }
}
