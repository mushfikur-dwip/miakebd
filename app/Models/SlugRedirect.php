<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A slug a page used to live at, and where it lives now.
 *
 * URLs that Google, Facebook posts and WhatsApp chats point at must keep
 * working after a rename, so an old slug answers with a 301 to the new one
 * instead of a 404 (see RootController::category).
 */
class SlugRedirect extends Model
{
    public const PRODUCT_CATEGORY = 'product_category';

    protected $fillable = ['type', 'from_slug', 'to_slug'];

    /**
     * Records that `$from` moved to `$to`, keeping every earlier name pointing
     * straight at the newest one (no redirect chains), and forgetting a
     * redirect whose old name has just been taken again.
     */
    public static function remember(string $type, string $from, string $to): void
    {
        if ($from === '' || $to === '' || $from === $to) {
            return;
        }

        static::where('type', $type)->where('to_slug', $from)->update(['to_slug' => $to]);
        static::where('type', $type)->where('from_slug', $to)->delete();
        static::updateOrCreate(['type' => $type, 'from_slug' => $from], ['to_slug' => $to]);
    }

    /** Null when there is none - or when the table is not migrated yet, so an
     *  unknown URL stays the 404 it always was rather than becoming a 500. */
    public static function target(string $type, string $from): ?string
    {
        try {
            return static::where('type', $type)->where('from_slug', $from)->value('to_slug');
        } catch (\Throwable $e) {
            return null;
        }
    }
}
