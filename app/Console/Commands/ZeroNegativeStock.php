<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Enums\Status;
use App\Enums\StockAdjustmentType;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\Stock;
use App\Models\StockAdjustment;
use App\Services\ProductVariationService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Before sales were checked against stock, the till could sell more than a
 * branch held and leave it below zero. This lists every item below zero; with
 * --fix it adds exactly what brings each one back to 0, written as an Add
 * adjustment per branch so the stock history shows where it came from.
 *
 * Branches are raised to 0. The unassigned pool - where damage is written -
 * may stay below zero while the branches cover it; it is raised only as far
 * as an item's shop-wide total needs to reach 0.
 *
 * Releasing a cancelled order's stock (orders:cancelled-stock) also raises
 * stock, so --fix waits until that is done: the other way round, the item
 * would end up above what is really there.
 */
class ZeroNegativeStock extends Command
{
    protected $signature = 'stock:zero-negatives
        {--fix : Add what brings every listed item back to 0}
        {--ignore-cancelled : Fix even though cancelled orders still hold stock}';

    protected $description = 'List stock below zero, and optionally bring it back to 0';

    public function handle(): int
    {
        $fixes = $this->fixes();

        if ($fixes->isEmpty()) {
            $this->info('No stock is below zero.');

            return self::SUCCESS;
        }

        $outlets  = Outlet::whereIn('id', $fixes->pluck('outlet_id')->filter()->unique())->pluck('name', 'id');
        $products = Product::withTrashed()->whereIn('id', $fixes->pluck('product_id')->unique())->get()->keyBy('id');

        $this->table(['product', 'sku', 'place', 'stock now', 'add'], $fixes->map(fn(array $fix) => [
            $this->name($products->get($fix['product_id']), $fix),
            $this->sku($products->get($fix['product_id']), $fix),
            $fix['outlet_id'] ? ($outlets[$fix['outlet_id']] ?? '#' . $fix['outlet_id']) : trans('all.label.unassigned'),
            $fix['held'],
            $fix['add'],
        ])->all());

        $units = (int) $fixes->sum('add');
        $items = $fixes->map(fn(array $fix) => $fix['item_type'] . '|' . $fix['item_id'])->unique()->count();

        if (!$this->option('fix')) {
            $this->line($items . ' ' . Str::plural('item', $items) . ' below zero at ' . $fixes->count() . ' '
                . Str::plural('place', $fixes->count()) . '. Run with --fix to add ' . $units . ' units and bring them back to 0.');

            return self::SUCCESS;
        }

        $held = $this->cancelledOrdersHoldingStock();
        if ($held > 0 && !$this->option('ignore-cancelled')) {
            $this->error($held . ' cancelled or rejected ' . Str::plural('order', $held) . ' still hold stock. Run php artisan orders:cancelled-stock '
                . '(and --fix if its list is right) first, or pass --ignore-cancelled. Nothing was changed.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($fixes, $products) {
            foreach ($fixes->groupBy(fn(array $fix) => $fix['outlet_id'] ?: 0) as $place => $placeFixes) {
                $adjustment = StockAdjustment::create([
                    'type'         => StockAdjustmentType::ADD,
                    'to_outlet_id' => $place ?: null,
                    'date'         => now(),
                    'note'         => 'Negative stock set to 0 (stock:zero-negatives)',
                ]);

                foreach ($placeFixes as $fix) {
                    Stock::create([
                        'product_id'      => $fix['product_id'],
                        'outlet_id'       => $fix['outlet_id'],
                        'model_type'      => StockAdjustment::class,
                        'model_id'        => $adjustment->id,
                        'item_type'       => $fix['item_type'],
                        'item_id'         => $fix['item_id'],
                        'variation_names' => $fix['item_type'] === ProductVariation::class ? $this->variationName($fix['item_id']) : null,
                        'sku'             => $this->sku($products->get($fix['product_id']), $fix),
                        'quantity'        => $fix['add'],
                        'price'           => 0,
                        'discount'        => 0,
                        'tax'             => 0,
                        'subtotal'        => 0,
                        'total'           => 0,
                        'status'          => Status::ACTIVE,
                    ]);
                }
            }
        });

        $this->info('Added ' . $units . ' units at ' . $fixes->count() . ' ' . Str::plural('place', $fixes->count()) . '. Nothing is below zero now.');

        return self::SUCCESS;
    }

    /**
     * What to add where: each branch below zero back to 0, then the pool as
     * far as each item's total still needs.
     */
    private function fixes(): Collection
    {
        $levels = Stock::where('status', Status::ACTIVE)
            ->selectRaw('item_type, item_id, outlet_id, MAX(product_id) as product_id, SUM(quantity) as quantity')
            ->groupBy('item_type', 'item_id', 'outlet_id')
            ->toBase()
            ->get();

        $fixes = collect();
        foreach ($levels->groupBy(fn($level) => $level->item_type . '|' . $level->item_id) as $places) {
            $total = 0;
            foreach ($places as $level) {
                $held = (int) round((float) $level->quantity);
                if ($level->outlet_id !== null && $held < 0) {
                    $fixes->push($this->fix($level, $held, -$held));
                    $held = 0;
                }
                $total += $held;
            }

            $pool = $places->first(fn($level) => $level->outlet_id === null);
            if ($total < 0 && $pool) {
                $fixes->push($this->fix($pool, (int) round((float) $pool->quantity), -$total));
            }
        }

        return $fixes->values();
    }

    private function fix(object $level, int $held, int $add): array
    {
        return [
            'product_id' => (int) $level->product_id,
            'item_type'  => $level->item_type,
            'item_id'    => (int) $level->item_id,
            'outlet_id'  => $level->outlet_id !== null ? (int) $level->outlet_id : null,
            'held'       => $held,
            'add'        => $add,
        ];
    }

    private function cancelledOrdersHoldingStock(): int
    {
        return Stock::where('model_type', Order::class)
            ->where('status', Status::ACTIVE)
            ->whereIn('model_id', Order::select('id')->whereIn('status', [OrderStatus::CANCELED, OrderStatus::REJECTED]))
            ->distinct()
            ->count('model_id');
    }

    private function name(?Product $product, array $fix): string
    {
        $name = $product?->name ?? '#' . $fix['product_id'];
        if ($fix['item_type'] === ProductVariation::class && ($variation = $this->variationName($fix['item_id']))) {
            $name .= ' (' . $variation . ')';
        }

        return $name;
    }

    private function sku(?Product $product, array $fix): ?string
    {
        if ($fix['item_type'] === ProductVariation::class) {
            return ProductVariation::find($fix['item_id'])?->sku ?? $product?->sku;
        }

        return $product?->sku;
    }

    private function variationName(int $variationId): ?string
    {
        $variation = ProductVariation::find($variationId);

        return $variation ? app(ProductVariationService::class)->ancestorsToString($variation) : null;
    }
}
