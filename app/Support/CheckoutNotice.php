<?php

namespace App\Support;

use App\Enums\Activity;

/**
 * Default for the checkout notice.
 *
 * SiteTableSeeder seeds this on a fresh install and a migration backfills it on
 * an existing one — but a site that has not migrated yet would otherwise show an
 * empty box in Settings → Site and no notice on checkout. Both resources fall
 * back here instead, so the feature works before the migration runs and the
 * migration only makes the value editable.
 *
 * Once the shop owner saves anything, the stored value wins and this is never
 * consulted again. Clearing the box stores an empty string, which is a real
 * value — so clearing it still hides the notice rather than resurrecting this
 * default.
 */
class CheckoutNotice
{
    public const TEXT = 'প্রযুক্তিগত ত্রুটির কারণে পণ্যের মূল্য ভুল দেখালে, Suglow অর্ডার বাতিলের অধিকার সংরক্ষণ করে। কাস্টমার সাপোর্টের কনফার্মেশন ছাড়া পেমেন্ট করবেন না।';

    /**
     * A stored empty string means "hidden on purpose" and is returned as-is;
     * only a genuinely absent key falls back to the default.
     */
    public static function text(array $info): ?string
    {
        return array_key_exists('site_checkout_notice', $info)
            ? $info['site_checkout_notice']
            : self::TEXT;
    }

    public static function status(array $info): int
    {
        return array_key_exists('site_checkout_notice_status', $info)
            ? (int) $info['site_checkout_notice_status']
            : Activity::ENABLE;
    }
}
