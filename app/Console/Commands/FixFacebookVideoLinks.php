<?php

namespace App\Console\Commands;

use App\Enums\VideoProvider;
use App\Models\ProductVideo;
use App\Support\FacebookVideoLink;
use Illuminate\Console\Command;

/**
 * One pass over the Facebook videos already saved.
 *
 * Videos added before the form learned to store the reel address may hold a
 * Share link or a page-video link with tracking on it, and the first shows
 * "Video unavailable" on the product page. Each is rewritten to its reel
 * address - the same thing saving it again in the form now does - and every
 * reel Facebook will not play on other websites is listed, so its audience
 * can be set to Public.
 */
class FixFacebookVideoLinks extends Command
{
    protected $signature = 'videos:fix-facebook {--dry-run : Report only, change nothing}';

    protected $description = 'Rewrite saved Facebook product videos to their reel address and list the ones Facebook will not play';

    public function handle(): int
    {
        $videos = ProductVideo::where('video_provider', VideoProvider::FACEBOOK)->orderBy('id')->get();
        if ($videos->isEmpty()) {
            $this->info('No Facebook videos saved.');
            return self::SUCCESS;
        }

        $rows = [];
        foreach ($videos as $video) {
            $link = FacebookVideoLink::fromPasted((string) $video->link);
            $reel = ($id = FacebookVideoLink::videoId($link))
                ? FacebookVideoLink::canonical($id)
                : (FacebookVideoLink::isShortLink($link) ? FacebookVideoLink::resolve($link) : null);

            $action = 'kept';
            if ($reel === null) {
                $action = 'NOT FIXED - paste the reel link again in the product\'s video form';
            } elseif ($reel !== $video->link) {
                $taken = ProductVideo::where('product_id', $video->product_id)
                    ->where('link', $reel)->where('id', '!=', $video->id)->exists();
                if ($taken) {
                    $action = 'duplicate of another video on this product - delete one';
                } else {
                    $action = $this->option('dry-run') ? 'would rewrite' : 'rewritten';
                    if (!$this->option('dry-run')) {
                        $video->update(['link' => $reel]);
                    }
                }
            }

            $playable = $reel ? FacebookVideoLink::playable($reel) : null;
            $rows[] = [
                $video->id,
                $video->product_id,
                $reel ?? $video->link,
                $action,
                $playable === null ? 'could not check' : ($playable ? 'yes' : 'NO - set the reel to Public'),
            ];
        }

        $this->table(['video', 'product', 'reel address', 'link', 'plays on the site'], $rows);

        return self::SUCCESS;
    }
}
