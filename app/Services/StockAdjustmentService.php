<?php

namespace App\Services;

use App\Enums\Status;
use App\Enums\StockAdjustmentType;
use App\Http\Requests\PaginateRequest;
use App\Http\Requests\StockAdjustmentRequest;
use App\Http\Requests\StockItemUpdateRequest;
use App\Libraries\QueryExceptionLibrary;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\Stock;
use App\Models\StockAdjustment;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StockAdjustmentService
{
    public ?StockAdjustment $stockAdjustment = null;

    protected array $stockAdjustmentFilter = [
        'type',
        'from_outlet_id',
        'to_outlet_id',
        'reference_no',
    ];

    /**
     * @throws Exception
     */
    public function list(PaginateRequest $request)
    {
        try {
            $requests    = $request->all();
            $method      = $request->get('paginate', 0) == 1 ? 'paginate' : 'get';
            $methodValue = $request->get('paginate', 0) == 1 ? $request->get('per_page', 10) : '*';
            $orderColumn = $request->get('order_column') ?? 'id';
            $orderType   = $request->get('order_type') ?? 'desc';

            return StockAdjustment::with('fromOutlet', 'toOutlet', 'creator')
                ->withCount('stocks')
                ->where(function ($query) use ($requests) {
                    foreach ($requests as $key => $value) {
                        if (in_array($key, $this->stockAdjustmentFilter) && !blank($value)) {
                            if ($key === 'reference_no') {
                                $query->where($key, 'like', '%' . $value . '%');
                            } else {
                                $query->where($key, $value);
                            }
                        }
                    }
                })
                ->orderBy($orderColumn, $orderType)
                ->$method($methodValue);
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function show(StockAdjustment $stockAdjustment): StockAdjustment
    {
        return $stockAdjustment->load('fromOutlet', 'toOutlet', 'creator', 'stocks.product');
    }

    /**
     * Writes the stock rows that make the adjustment real.
     *
     * TRANSFER writes a matching pair per item so the grand total the website
     * shows never moves - only which branch holds the goods changes. ADD and
     * REMOVE write a single row, which is the point: they exist to correct a
     * branch count that reality disagrees with.
     *
     * @throws Exception
     */
    public function store(StockAdjustmentRequest $request): StockAdjustment
    {
        try {
            DB::transaction(function () use ($request) {
                $type = (int) $request->type;

                $this->stockAdjustment = StockAdjustment::create([
                    'type'           => $type,
                    'from_outlet_id' => in_array($type, [StockAdjustmentType::ADD, StockAdjustmentType::RECALCULATE]) ? null : ($request->from_outlet_id ?: null),
                    'to_outlet_id'   => $type === StockAdjustmentType::REMOVE ? null : ($request->to_outlet_id ?: null),
                    'date'           => date('Y-m-d H:i:s', strtotime($request->date)),
                    'reference_no'   => $request->reference_no,
                    'note'           => $request->note ?: '',
                    // A stock correction is only auditable if it names who
                    // made it, so stamp the admin who submitted the form.
                    'creator_type'   => Auth::check() ? get_class(Auth::user()) : null,
                    'creator_id'     => Auth::id(),
                ]);

                foreach (json_decode($request->products, true) as $product) {
                    $quantity = abs((int) $product['quantity']);

                    if ($type === StockAdjustmentType::RECALCULATE) {
                        $this->recalculate($product, $this->stockAdjustment->to_outlet_id, $quantity);
                        continue;
                    }

                    if ($type !== StockAdjustmentType::ADD) {
                        $this->writeStockRow($product, $this->stockAdjustment->from_outlet_id, -$quantity);
                    }

                    if ($type !== StockAdjustmentType::REMOVE) {
                        $this->writeStockRow($product, $this->stockAdjustment->to_outlet_id, $quantity);
                    }
                }
            });

            return $this->stockAdjustment;
        } catch (Exception $exception) {
            DB::rollBack();
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * Sets one product's stock at several branches in a single save - what the
     * edit dialog on the stock screen submits.
     *
     * Each branch that actually changed becomes its own RECALCULATE adjustment,
     * so the history reads "Recalculate → Gulshan" per branch and deleting one
     * reverses only that branch. Branches left at their current number produce
     * no record at all.
     *
     * The item's SKU and variation label are resolved here rather than trusted
     * from the form, so a tampered payload cannot write a stock row that claims
     * to be a different product.
     *
     * @throws Exception
     */
    public function updateItemStock(StockItemUpdateRequest $request): void
    {
        try {
            DB::transaction(function () use ($request) {
                $product     = Product::findOrFail($request->product_id);
                $variationId = (int) $request->get('variation_id', 0);
                $variation   = $variationId > 0
                    ? ProductVariation::where('product_id', $product->id)->findOrFail($variationId)
                    : null;

                $line = [
                    'product_id'      => $product->id,
                    'variation_id'    => $variation?->id ?? 0,
                    'sku'             => $variation?->sku ?? $product->sku,
                    'variation_names' => $variation ? app(ProductVariationService::class)->ancestorsToString($variation) : null,
                ];

                foreach (json_decode($request->stocks, true) as $entry) {
                    $outletId = blank($entry['outlet_id'] ?? null) || (int) $entry['outlet_id'] === 0
                        ? null
                        : (int) $entry['outlet_id'];

                    $target  = (int) $entry['quantity'];
                    $current = $this->currentQuantity($line, $outletId);

                    if ($target - $current === 0) {
                        continue;
                    }

                    $this->stockAdjustment = StockAdjustment::create([
                        'type'         => StockAdjustmentType::RECALCULATE,
                        'to_outlet_id' => $outletId,
                        'date'         => now(),
                        'note'         => trans('all.label.stock_edit'),
                        'creator_type' => Auth::check() ? get_class(Auth::user()) : null,
                        'creator_id'   => Auth::id(),
                    ]);

                    $this->writeStockRow($line, $outletId, $target - $current);
                }
            });
        } catch (Exception $exception) {
            DB::rollBack();
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * Deleting the adjustment deletes its stock rows, which reverses it -
     * the same way deleting a purchase reverses the goods it brought in.
     *
     * @throws Exception
     */
    public function destroy(StockAdjustment $stockAdjustment): void
    {
        try {
            DB::transaction(function () use ($stockAdjustment) {
                $stockAdjustment->stocks()->delete();
                $stockAdjustment->delete();
            });
        } catch (Exception $exception) {
            DB::rollBack();
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * Makes the branch hold exactly $target of this item.
     *
     * The ledger is append-only - nothing rewrites or deletes the rows a
     * purchase or a sale left behind - so setting an absolute number means
     * writing the difference between what is on record and what was typed.
     * The sum then lands on $target. A difference of zero writes nothing,
     * which keeps a re-submitted count from littering the ledger.
     *
     * The current figure is read inside the same transaction as the write, so
     * two counts submitted at once cannot both compute from the same starting
     * number and leave the branch on the wrong total.
     */
    private function recalculate(array $product, $outletId, int $target): void
    {
        $difference = $target - $this->currentQuantity($product, $outletId);

        if ($difference === 0) {
            return;
        }

        $this->writeStockRow($product, $outletId, $difference);
    }

    /**
     * What the branch holds for this item right now.
     *
     * Read under lockForUpdate inside the caller's transaction: two counts
     * submitted at once must not both compute their difference from the same
     * starting number and leave the branch on neither figure.
     */
    private function currentQuantity(array $product, $outletId): int
    {
        $isVariation = !blank($product['variation_id'] ?? null) && (int) $product['variation_id'] > 0;

        return (int) Stock::where('status', Status::ACTIVE)
            ->where('item_type', $isVariation ? ProductVariation::class : Product::class)
            ->where('item_id', $isVariation ? $product['variation_id'] : $product['product_id'])
            ->when(
                blank($outletId),
                fn($query) => $query->whereNull('outlet_id'),
                fn($query) => $query->where('outlet_id', $outletId)
            )
            ->lockForUpdate()
            ->sum('quantity');
    }

    private function writeStockRow(array $product, $outletId, int $quantity): void
    {
        $isVariation = !blank($product['variation_id'] ?? null) && (int) $product['variation_id'] > 0;

        Stock::create([
            'product_id'      => $product['product_id'],
            'outlet_id'       => $outletId,
            'model_type'      => StockAdjustment::class,
            'model_id'        => $this->stockAdjustment->id,
            'item_type'       => $isVariation ? ProductVariation::class : Product::class,
            'item_id'         => $isVariation ? $product['variation_id'] : $product['product_id'],
            'variation_names' => $product['variation_names'] ?? null,
            'sku'             => $product['sku'] ?? null,
            'quantity'        => $quantity,
            // An adjustment moves goods, not money. The money columns stay at
            // zero so it never shows up in a sales or purchase total.
            'price'           => 0,
            'discount'        => 0,
            'tax'             => 0,
            'subtotal'        => 0,
            'total'           => 0,
            'status'          => Status::ACTIVE,
        ]);
    }
}
