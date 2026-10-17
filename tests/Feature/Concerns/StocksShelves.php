<?php

namespace Tests\Feature\Concerns;

use App\Enums\Status;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Stock;

/**
 * A till sale beyond what the branch holds is refused (see StockGuard), so a
 * test that rings up sales first puts stock on the branch's shelf.
 */
trait StocksShelves
{
    protected function stockUp(Product $product, Outlet $outlet, int $quantity = 1000): void
    {
        Stock::create([
            'product_id' => $product->id,
            'outlet_id'  => $outlet->id,
            'model_type' => Product::class,
            'model_id'   => $product->id,
            'item_type'  => Product::class,
            'item_id'    => $product->id,
            'quantity'   => $quantity,
            'status'     => Status::ACTIVE,
        ]);
    }
}
