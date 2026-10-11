<?php

namespace App\Services;

use App\Enums\AddressType;
use App\Enums\Ask;
use App\Enums\OrderType;
use App\Libraries\AppLibrary;
use App\Models\Order;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * "New Online Order" in the shop's Telegram chat - the same chat as the POS
 * alerts. Sent from PaymentController::sendOrderNotifications(), so only for
 * orders that were actually confirmed (cash on delivery confirmed, online
 * payment completed, or paid from the wallet), never for abandoned checkouts.
 */
class OnlineOrderTelegramNotificationService
{
    /** Items listed in full; the rest are counted. */
    private const ITEM_LIMIT = 10;

    public function __construct(private TelegramNotifier $telegram)
    {
    }

    public function send(int $orderId): void
    {
        try {
            if (!$this->telegram->enabled()) {
                return;
            }

            $order = Order::with(['user', 'address', 'outlet', 'outletAddress', 'paymentMethod', 'orderProducts.product'])->find($orderId);
            if (!$order || (int) $order->order_type === OrderType::POS) {
                return;
            }

            // Only an order someone went through with: cash on delivery
            // confirmed, a payment settled, or the wallet charged - each sets
            // active. The confirmation page opens for any order id, and a
            // checkout abandoned at the gateway is still sitting at NO.
            if ((int) $order->active !== Ask::YES) {
                return;
            }

            // Once per order, however often the confirmation is reached.
            if (!Cache::add('online_order_telegram_notification_sent_' . $orderId, true, now()->addDay())) {
                return;
            }

            $this->telegram->send($this->message($order), 'online order');
        } catch (Throwable $exception) {
            Log::info($exception->getMessage());
        }
    }

    private function message(Order $order): string
    {
        $e = fn (?string $value) => TelegramNotifier::escape($value);

        $lines = [
            '<b>New Online Order</b>',
            'Order No: ' . $e($order->order_serial_no),
            'Customer: ' . $e($this->customer($order)),
            $this->destination($order),
            'Items:',
        ];

        $items = $order->orderProducts->values();
        foreach ($items->take(self::ITEM_LIMIT) as $item) {
            $name    = $item->product?->name ?? 'Product';
            $variant = filled($item->variation_names) ? ' (' . $item->variation_names . ')' : '';
            $lines[] = '- ' . $e($name . $variant) . ' x ' . abs((int) $item->quantity);
        }
        if ($items->count() > self::ITEM_LIMIT) {
            $lines[] = '+' . ($items->count() - self::ITEM_LIMIT) . ' more';
        }

        // A wallet-covered order can still carry the gateway picked before the
        // wallet was applied; "Cash On Delivery" would have the courier collect.
        $paidBy = (float) $order->wallet_discount > 0 && (float) $order->wallet_discount >= (float) $order->total
            ? 'Wallet'
            : ($order->paymentMethod?->name ?? 'N/A');

        $lines[] = 'Total: ' . $e(AppLibrary::currencyAmountFormat($order->total));
        $lines[] = 'Payment: ' . $e($paidBy . ' - ' . trans('payment_status.' . $order->payment_status));
        $lines[] = 'Date: ' . $e(AppLibrary::datetime($order->order_datetime));
        $lines[] = 'Details: ' . $e(rtrim((string) config('app.url'), '/') . '/admin/online-orders/show/' . $order->id);

        return implode("\n", $lines);
    }

    /**
     * Who placed the order and their number, written as the admin order page
     * writes it (+8801712345678 - which Telegram also makes tappable). The
     * delivery address stands in for anything the account lacks.
     */
    private function customer(Order $order): string
    {
        $shipping = $this->shipping($order);
        $name     = $order->user?->name ?: $shipping?->full_name;
        $phone    = match (true) {
            filled($order->user?->phone) => $order->user->country_code . $order->user->phone,
            filled($shipping?->phone)    => $shipping->country_code . $shipping->phone,
            default                      => null,
        };

        return implode(' - ', array_filter([$name, $phone], fn ($part) => filled($part))) ?: 'N/A';
    }

    private function destination(Order $order): string
    {
        if ((int) $order->order_type === OrderType::PICK_UP) {
            return 'Pickup: ' . TelegramNotifier::escape($order->outlet?->name ?: ($order->outletAddress?->name ?? 'N/A'));
        }

        $shipping = $this->shipping($order);
        $parts    = array_filter([$shipping?->address, $shipping?->city, $shipping?->state], fn ($part) => filled($part));

        return 'Delivery: ' . TelegramNotifier::escape($parts ? implode(', ', $parts) : 'N/A');
    }

    private function shipping(Order $order)
    {
        return $order->address->firstWhere('address_type', AddressType::SHIPPING) ?? $order->address->first();
    }
}
