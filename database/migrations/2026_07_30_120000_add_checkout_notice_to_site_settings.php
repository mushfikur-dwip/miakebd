<?php

use App\Enums\Activity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The checkout notice is seeded for fresh installs by SiteTableSeeder, but
     * seeders do not run again on an existing site — so this adds the two keys
     * for installs that already exist. Without them the admin field saves fine
     * but reads back empty on the first load.
     */
    private const KEYS = [
        'site_checkout_notice' => 'প্রযুক্তিগত ত্রুটির কারণে পণ্যের মূল্য ভুল দেখালে, Suglow অর্ডার বাতিলের অধিকার সংরক্ষণ করে। কাস্টমার সাপোর্টের কনফার্মেশন ছাড়া পেমেন্ট করবেন না।',
        'site_checkout_notice_status' => null, // filled with Activity::ENABLE below
    ];

    public function up(): void
    {
        if (!Schema::hasTable('settings')) {
            return;
        }

        $values = self::KEYS;
        $values['site_checkout_notice_status'] = Activity::ENABLE;

        foreach ($values as $key => $value) {
            $exists = DB::table('settings')
                ->where(['group' => 'site', 'key' => $key])
                ->exists();

            if ($exists) {
                continue;
            }

            // Matches the payload shape the settings package writes:
            // {"$value": ..., "$cast": null}
            DB::table('settings')->insert([
                'group' => 'site',
                'key' => $key,
                'payload' => json_encode(['$value' => $value, '$cast' => null], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('settings')) {
            return;
        }

        DB::table('settings')
            ->where('group', 'site')
            ->whereIn('key', array_keys(self::KEYS))
            ->delete();
    }
};
