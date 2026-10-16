<?php

namespace App\Services;

use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariation;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * "Last one left" in the shop chat: the item, the branch, what it still
 * holds there and across every branch - so it can be reordered, or moved
 * from a branch that has more, before it runs out.
 */
class LowStockTelegramNotificationService
{
    public function __construct(private TelegramNotifier $telegram, private StockGuard $stock)
    {
    }

    /** @param array{product_id:int, item_type:string, item_id:int, outlet_id:int, left:int} $info */
    public function send(array $info): void
    {
        try {
            // One message per item, branch and level, even if the same sale
            // reports it twice.
            $key = 'low_stock_telegram_' . md5($info['item_type'] . '|' . $info['item_id'] . '|' . $info['outlet_id'] . '|' . $info['left']);
            if (!Cache::add($key, true, now()->addMinutes(10))) {
                return;
            }

            if (!$this->telegram->enabled()) {
                return;
            }

            $product = Product::withTrashed()->find($info['product_id']);
            if (!$product) {
                return;
            }

            $this->telegram->send($this->message($product, $info), 'low stock');
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
        }
    }

    private function message(Product $product, array $info): string
    {
        $variation = $info['item_type'] === ProductVariation::class ? ProductVariation::find($info['item_id']) : null;
        $name      = $product->name . ($variation ? ' (' . app(ProductVariationService::class)->ancestorsToString($variation) . ')' : '');
        $branch    = Outlet::find($info['outlet_id'])?->name ?? 'N/A';
        $left      = (int) $info['left'];
        $url       = rtrim(config('app.url'), '/') . '/admin/stock';

        return implode("\n", [
            '<b>Low stock</b>',
            'Product: ' . TelegramNotifier::escape($name),
            'SKU: ' . TelegramNotifier::escape($variation?->sku ?: $product->sku),
            'Branch: ' . TelegramNotifier::escape($branch) . ' - ' . ($left > 0 ? $left . ' left' : 'out of stock'),
            'All branches: ' . $this->stock->held($info['item_type'], (int) $info['item_id'], null),
            'Stock: ' . TelegramNotifier::escape($url),
        ]);
    }
}
