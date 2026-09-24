<?php

namespace App\Services;

use App\Enums\SwitchBox;
use App\Models\NotificationAlert;
use App\Models\User;

/**
 * The SMS a customer gets when the till saves their details.
 *
 * The same three conditions as the order SMS, so switching it on cannot spray
 * messages:
 * - Settings > Notification Alert > SMS has "POS Customer Message" on, with a
 *   message;
 * - the record has a name and a phone (the till's form requires both);
 * - an SMS gateway is configured and enabled.
 *
 * Only the till's form reaches this, through CustomerService::storePosCustomer().
 * Customers added on the admin Customers page are not texted.
 *
 * The message may use {name}.
 */
class PosCustomerSmsNotificationBuilder
{
    public const ALERT = 'pos_customer_message';

    public function send(int $userId): void
    {
        $alert = NotificationAlert::where('language', self::ALERT)->first();
        if (!$alert || $alert->sms != SwitchBox::ON || blank($alert->sms_message)) {
            return;
        }

        $user = User::find($userId);
        if (!$user || blank($user->name) || blank($user->phone)) {
            return;
        }

        $sms = app(SmsManagerService::class)->gateway(app(SmsService::class)->gateway());
        if (!$sms->status()) {
            return;
        }

        $sms->send($user->country_code, $user->phone, strtr($alert->sms_message, [
            '{name}' => $user->name,
        ]));
    }
}
