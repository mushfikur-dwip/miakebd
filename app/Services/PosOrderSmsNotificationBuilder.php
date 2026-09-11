<?php

namespace App\Services;

use App\Enums\SwitchBox;
use App\Libraries\AppLibrary;
use App\Models\NotificationAlert;
use App\Models\Order;
use App\Models\User;

/**
 * The SMS a customer gets after buying at the till.
 *
 * Sent only when all of these hold, so switching it on cannot spray messages:
 * - Settings > Notification Alert > SMS has "POS Order Message" on, with a
 *   message;
 * - the sale was booked to a real customer with both a name and a phone. The
 *   seeded Walking Customer carries a placeholder phone (125444455) and every
 *   anonymous sale is booked to it, so it is skipped by its username;
 * - an SMS gateway is configured and enabled.
 *
 * The message may use {name}, {order} and {total}. {total} is the bare amount
 * with no currency symbol: a "৳" would make the whole SMS Unicode, which cuts
 * each part from 160 characters to 70 and multiplies what it costs to send.
 */
class PosOrderSmsNotificationBuilder
{
    public const ALERT = 'pos_order_message';

    private const WALKING_CUSTOMER = 'default-customer';

    public function send(int $orderId): void
    {
        $alert = NotificationAlert::where('language', self::ALERT)->first();
        if (!$alert || $alert->sms != SwitchBox::ON || blank($alert->sms_message)) {
            return;
        }

        $order = Order::find($orderId);
        $user  = $order ? User::find($order->user_id) : null;
        if (!$user || $user->username === self::WALKING_CUSTOMER || blank($user->name) || blank($user->phone)) {
            return;
        }

        $sms = app(SmsManagerService::class)->gateway(app(SmsService::class)->gateway());
        if (!$sms->status()) {
            return;
        }

        $sms->send($user->country_code, $user->phone, strtr($alert->sms_message, [
            '{name}'  => $user->name,
            '{order}' => $order->order_serial_no,
            '{total}' => AppLibrary::flatAmountFormat($order->total),
        ]));
    }
}
