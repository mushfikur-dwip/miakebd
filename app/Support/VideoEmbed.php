<?php

namespace App\Support;

use App\Enums\VideoOrientation;
use App\Enums\VideoProvider;

/**
 * The address a product video plays from.
 *
 * The storefront shows each video in an <iframe>, and only a provider's
 * embed player may be framed - the watch page an admin copies from the
 * browser bar refuses. So the link is kept as it was pasted, and this turns
 * it into the player address when it is shown. Links already in player form,
 * and anything not recognised, pass through unchanged.
 */
class VideoEmbed
{
    public static function url(int $provider, string $link): string
    {
        $link = trim($link);

        return match ($provider) {
            VideoProvider::FACEBOOK    => self::facebook($link),
            VideoProvider::YOUTUBE     => self::youtube($link),
            VideoProvider::VIMEO       => self::vimeo($link),
            VideoProvider::DAILYMOTION => self::dailymotion($link),
            default                    => $link,
        };
    }

    /**
     * Whether the video should play in a tall 9:16 frame rather than 16:9.
     *
     * Facebook's player fills whatever frame it is given, so a reel in a wide
     * frame is a narrow strip with bars either side. A set orientation wins.
     * Otherwise: YouTube Shorts and any /reel/ link are tall, and so is every
     * other Facebook video - the shop posts reels there, and the link of a
     * page video does not say which shape it is. Everything else is wide.
     */
    public static function isPortrait(int $provider, string $link, ?int $orientation): bool
    {
        if ($orientation === VideoOrientation::PORTRAIT) {
            return true;
        }
        if ($orientation === VideoOrientation::LANDSCAPE) {
            return false;
        }
        if (preg_match('~/(reels?|shorts)/|/share/r/~i', $link)) {
            return true;
        }

        return $provider === VideoProvider::FACEBOOK;
    }

    /**
     * Whether a link is a Facebook video address at all: facebook.com in any
     * of its forms, or a fb.watch short link.
     */
    public static function isFacebook(string $link): bool
    {
        $host = strtolower((string) parse_url(trim($link), PHP_URL_HOST));

        return $host === 'fb.watch'
            || $host === 'fb.com'
            || $host === 'facebook.com'
            || str_ends_with($host, '.facebook.com');
    }

    // Facebook's own video player takes the post's address as `href` and
    // copes with page videos, watch links, reels and fb.watch alike.
    private static function facebook(string $link): string
    {
        if (str_contains($link, 'facebook.com/plugins/video.php')) {
            return $link;
        }

        return 'https://www.facebook.com/plugins/video.php?href=' . rawurlencode($link) . '&show_text=false&t=0';
    }

    private static function youtube(string $link): string
    {
        if (preg_match('~(?:youtube\.com/(?:watch\?(?:.*&)?v=|shorts/|embed/|live/)|youtu\.be/)([A-Za-z0-9_-]{11})~', $link, $match)) {
            return 'https://www.youtube.com/embed/' . $match[1];
        }

        return $link;
    }

    private static function vimeo(string $link): string
    {
        if (preg_match('~vimeo\.com/(?:video/)?(\d+)~', $link, $match)) {
            return 'https://player.vimeo.com/video/' . $match[1];
        }

        return $link;
    }

    private static function dailymotion(string $link): string
    {
        if (preg_match('~(?:dailymotion\.com/(?:embed/)?video/|dai\.ly/)([A-Za-z0-9]+)~', $link, $match)) {
            return 'https://www.dailymotion.com/embed/video/' . $match[1];
        }

        return $link;
    }
}
