<?php

namespace App\Services;

use App\Enums\Ask;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Libraries\AppLibrary;
use App\Models\Order;
use Dipokhalder\Settings\Facades\Settings;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PosOrderTelegramNotificationService
{
    public function send(int $orderId): void
    {
        try {
            $settings = array_merge([
                'telegram_status'    => Ask::NO,
                'telegram_bot_token' => '',
                'telegram_chat_id'   => '',
            ], Settings::group('telegram')->all());

            if ((int)$settings['telegram_status'] !== Ask::YES) {
                return;
            }

            if (blank($settings['telegram_bot_token']) || blank($settings['telegram_chat_id'])) {
                return;
            }

            $order = Order::with(['user', 'outlet'])->find($orderId);
            if (!$order || (int)$order->order_type !== OrderType::POS || (int)$order->payment_status !== PaymentStatus::PAID) {
                return;
            }

            $response = Http::timeout(8)->asForm()->post(
                'https://api.telegram.org/bot' . $settings['telegram_bot_token'] . '/sendMessage',
                [
                    'chat_id'                  => $settings['telegram_chat_id'],
                    'text'                     => $this->message($order),
                    'parse_mode'               => 'HTML',
                    'disable_web_page_preview' => true,
                ]
            );

            if ($response->failed()) {
                Log::info('Telegram POS order notification failed: ' . $response->body());
            }
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
        }
    }

    private function message(Order $order): string
    {
        $customer = trim(($order?->user?->name ?? '') . ' ' . ($order?->user?->phone ?? ''));
        $url      = rtrim(config('app.url'), '/') . '/admin/pos-orders/show/' . $order->id;

        return implode("\n", [
            '<b>New POS Order</b>',
            'Order No: ' . $this->escape($order->order_serial_no),
            'Branch: ' . $this->escape($order?->outlet?->name ?? 'N/A'),
            'Customer: ' . $this->escape($customer ?: 'Walk-in Customer'),
            'Total: ' . $this->escape(AppLibrary::currencyAmountFormat($order->total)),
            'Payment Method: ' . $this->escape(trans('posPaymentMethod.' . $order->pos_payment_method)),
            'Order Status: ' . $this->escape(trans('orderStatus.' . $order->status)),
            'Date: ' . $this->escape(AppLibrary::datetime($order->order_datetime)),
            'Details: ' . $this->escape($url),
        ]);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
