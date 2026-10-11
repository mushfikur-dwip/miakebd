<?php

namespace App\Services;

use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Libraries\AppLibrary;
use App\Models\Order;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PosOrderTelegramNotificationService
{
    public function __construct(private TelegramNotifier $telegram)
    {
    }

    public function send(int $orderId): void
    {
        try {
            if (!Cache::add('pos_order_telegram_notification_sent_' . $orderId, true, now()->addDay())) {
                return;
            }

            if (!$this->telegram->enabled()) {
                return;
            }

            $order = Order::with(['user', 'outlet'])->find($orderId);
            if (!$order || (int)$order->order_type !== OrderType::POS || (int)$order->payment_status !== PaymentStatus::PAID) {
                return;
            }

            $this->telegram->send($this->message($order), 'POS order');
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
        return TelegramNotifier::escape($value);
    }
}
