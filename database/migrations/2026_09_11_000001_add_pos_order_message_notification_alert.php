<?php

use App\Enums\SwitchBox;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The SMS a customer gets after buying at the till.
     *
     * Settings > Notification Alert lists every row of this table, so adding the
     * row is what puts the switch and the editable message on that screen. It
     * ships switched off: nothing is sent until the shop turns it on. Inserted
     * only if missing, so running it twice changes nothing.
     */
    private const LANGUAGE = 'pos_order_message';

    public function up(): void
    {
        if (!Schema::hasTable('notification_alerts')) {
            return;
        }

        if (DB::table('notification_alerts')->where('language', self::LANGUAGE)->exists()) {
            return;
        }

        $message = 'Dear {name}, thank you for shopping with us. Order #{order}, total Tk {total}.';

        DB::table('notification_alerts')->insert([
            'name'                      => 'POS Order Message',
            'language'                  => self::LANGUAGE,
            'mail_message'              => $message,
            'sms_message'               => $message,
            'push_notification_message' => $message,
            'mail'                      => SwitchBox::OFF,
            'sms'                       => SwitchBox::OFF,
            'push_notification'         => SwitchBox::OFF,
            'created_at'                => now(),
            'updated_at'                => now(),
        ]);
    }

    public function down(): void
    {
        if (Schema::hasTable('notification_alerts')) {
            DB::table('notification_alerts')->where('language', self::LANGUAGE)->delete();
        }
    }
};
