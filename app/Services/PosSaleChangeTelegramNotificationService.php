<?php

namespace App\Services;

use App\Libraries\AppLibrary;
use App\Models\PosSaleChange;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Tells the shop's Telegram chat the moment a till sale is reversed or its
 * payment method changed - what, by whom and why - so a correction made to
 * hide money is seen while it can still be asked about.
 */
class PosSaleChangeTelegramNotificationService
{
    public function __construct(private TelegramNotifier $telegram)
    {
    }

    public function send(int $changeId): void
    {
        try {
            if (!Cache::add('pos_sale_change_telegram_sent_' . $changeId, true, now()->addDay())) {
                return;
            }

            if (!$this->telegram->enabled()) {
                return;
            }

            $change = PosSaleChange::with(['order', 'outlet', 'creator'])->find($changeId);
            if (!$change || !$change->order) {
                return;
            }

            $this->telegram->send($this->message($change), 'POS sale change');
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
        }
    }

    private function message(PosSaleChange $change): string
    {
        $order = $change->order;
        $url   = rtrim(config('app.url'), '/') . '/admin/pos-orders/show/' . $order->id;

        $lines = [
            $change->action === PosSaleChange::REVERSE ? '<b>POS sale reversed</b>' : '<b>POS payment method changed</b>',
            'Order No: ' . $this->escape($order->order_serial_no),
            'Branch: ' . $this->escape($change->outlet?->name ?? 'N/A'),
            'Amount: ' . $this->escape(AppLibrary::currencyAmountFormat($change->amount)),
        ];

        $lines[] = $change->action === PosSaleChange::REVERSE
            ? 'Paid by: ' . $this->escape($this->method($change->from_method, $change->from_note))
            : 'Method: ' . $this->escape($this->method($change->from_method, $change->from_note) . ' → ' . $this->method($change->to_method, $change->to_note));

        return implode("\n", array_merge($lines, [
            'By: ' . $this->escape($change->creator?->name ?? 'N/A'),
            'Reason: ' . $this->escape($change->reason),
            'Sold: ' . $this->escape(AppLibrary::datetime($order->order_datetime)),
            'Changed: ' . $this->escape(AppLibrary::datetime($change->created_at)),
            'Details: ' . $this->escape($url),
        ]));
    }

    // "Card (4321)": the method and the card digits or TrxID it was paid with.
    private function method(?int $method, ?string $note): string
    {
        $name = trans('posPaymentMethod.' . $method);

        return blank($note) ? $name : $name . ' (' . $note . ')';
    }

    private function escape(?string $value): string
    {
        return TelegramNotifier::escape($value);
    }
}
