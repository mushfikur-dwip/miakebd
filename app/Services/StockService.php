<?php

namespace App\Services;

use App\Enums\Ask;
use Exception;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\Stock;
use App\Models\Outlet;
use App\Enums\Status;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Pagination\Paginator;
use App\Http\Requests\PaginateRequest;
use App\Libraries\QueryExceptionLibrary;
use Illuminate\Pagination\LengthAwarePaginator;

class StockService
{
    public $items;
    public $links;
    protected $stockFilter = [
        'product_name',
        'sku',
        'status',
    ];

    /**
     * The stock table is driven by the catalogue, not by the stock ledger.
     *
     * It used to start from `stocks` and group the rows it found, which meant a
     * product only ever appeared once something had moved for it - a product
     * that had never been purchased was simply absent from the screen, so most
     * of the catalogue was invisible here and there was no way to give it an
     * opening count. Starting from `products` lists everything and shows a
     * genuine zero where nothing has moved yet.
     *
     * @throws Exception
     */
    public function list(PaginateRequest $request)
    {
        try {
            $method      = $request->get('paginate', 0) == 1 ? 'paginate' : 'get';
            $methodValue = $request->get('paginate', 0) == 1 ? $request->get('per_page', 10) : '*';
            $orderColumn = in_array($request->get('order_column'), ['id', 'name', 'sku']) ? $request->get('order_column') : 'id';
            $orderType   = $request->get('order_type') === 'asc' ? 'asc' : 'desc';

            // 0 is how the branch filter asks for the unassigned pool - the
            // rows recorded before branch-wise stock existed, plus website
            // delivery orders, which belong to no branch.
            $outletFilter = $request->get('outlet_id');
            $productName  = $request->get('product_name');
            // Barcodes in this app are the SKU: the barcode image is generated
            // from it and the POS scanner resolves a scan by SKU lookup. So one
            // box searches both, and a scan into it finds the row.
            $sku          = $request->get('sku');
            $status       = $request->get('status');

            // Filtering to one branch narrows the breakdown too, otherwise
            // every other branch would read 0 when it is simply filtered out.
            // Filtering to the unassigned pool leaves no branch columns at all.
            $outletNames = Outlet::orderBy('name')->pluck('name', 'id');
            if (!blank($outletFilter)) {
                $outletNames = (int) $outletFilter === 0
                    ? collect()
                    : $outletNames->only([(int) $outletFilter]);
            }

            $products = Product::query()
                ->when(!blank($productName), fn($query) => $query->where('name', 'like', '%' . $productName . '%'))
                ->when(!blank($status), fn($query) => $query->where('status', $status))
                ->when(!blank($sku), function ($query) use ($sku) {
                    $query->where(function ($query) use ($sku) {
                        $query->where('sku', 'like', '%' . $sku . '%')
                            ->orWhereHas('variations', fn($variationQuery) => $variationQuery->where('sku', 'like', '%' . $sku . '%'));
                    });
                })
                ->orderBy($orderColumn, $orderType)
                ->get(['id', 'name', 'sku', 'status', 'can_purchasable']);

            if ($products->isEmpty()) {
                $this->items = [];

                return $method == 'paginate'
                    ? $this->paginate($this->items, $methodValue, null, URL::to('/') . '/api/admin/stock')
                    : $this->items;
            }

            $productIds  = $products->pluck('id')->all();
            $variations  = $this->sellableVariations($productIds, $sku);
            $quantities  = $this->quantities($productIds, $outletFilter);

            $this->items = [];

            foreach ($products as $product) {
                $productVariations = $variations->get($product->id);

                if (blank($productVariations)) {
                    $this->items[] = $this->row($product, Product::class, $product->id, null, $product->sku, $quantities, $outletNames);
                    continue;
                }

                foreach ($productVariations as $variation) {
                    $this->items[] = $this->row($product, ProductVariation::class, $variation['id'], $variation['names'], $variation['sku'], $quantities, $outletNames);
                }
            }

            if ($method == 'paginate') {
                return $this->paginate($this->items, $methodValue, null, URL::to('/') . '/api/admin/stock');
            }

            return $this->items;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * Current quantity of one sellable item at one branch. Used by the stock
     * adjustment screen so a recalculate starts from the number on record
     * instead of the operator guessing at it.
     */
    public function itemQuantity($productId, $variationId, $outletId): int
    {
        $isVariation = !blank($variationId) && (int) $variationId > 0;

        return (int) Stock::where('status', Status::ACTIVE)
            ->where('item_type', $isVariation ? ProductVariation::class : Product::class)
            ->where('item_id', $isVariation ? $variationId : $productId)
            ->when(
                blank($outletId) || (int) $outletId === 0,
                fn($query) => $query->whereNull('outlet_id'),
                fn($query) => $query->where('outlet_id', $outletId)
            )
            ->sum('quantity');
    }

    /**
     * Leaf variations - the ones that actually carry stock - keyed by product,
     * each with its display name assembled from the attribute options on the
     * way down the tree.
     *
     * The whole tree is pulled in one query and walked in PHP. Asking the
     * database for each leaf's ancestors instead would be one query per
     * variation, which on this catalogue is thousands per page load.
     */
    private function sellableVariations(array $productIds, $sku): Collection
    {
        $all = ProductVariation::whereIn('product_id', $productIds)
            ->with('productAttributeOption:id,name')
            ->get(['id', 'product_id', 'parent_id', 'product_attribute_option_id', 'sku']);

        if ($all->isEmpty()) {
            return collect();
        }

        $byId = $all->keyBy('id');

        return $all->filter(fn($variation) => !blank($variation->sku))
            // A SKU search that matched on a variation should not drag in that
            // product's other variations.
            ->filter(fn($variation) => blank($sku) || Str::contains(Str::lower($variation->sku), Str::lower($sku)))
            ->map(fn($variation) => [
                'id'   => $variation->id,
                'sku'  => $variation->sku,
                'names' => $this->variationNames($variation, $byId),
                'product_id' => $variation->product_id,
            ])
            ->groupBy('product_id');
    }

    /**
     * Walks a leaf back up to the root and joins the option names the same way
     * ancestorsToString does, so the label here matches the one written onto
     * stock rows elsewhere.
     */
    private function variationNames($variation, Collection $byId): string
    {
        $names   = [];
        $current = $variation;
        $guard   = 0;

        // The guard is against a parent_id cycle in the data. Without it a bad
        // row would spin here forever and hang the request.
        while ($current && $guard++ < 20) {
            $name = $current->productAttributeOption?->name;
            if (!blank($name)) {
                $names[] = Str::ucfirst($name);
            }
            $current = $current->parent_id ? $byId->get($current->parent_id) : null;
        }

        return implode(' | ', array_reverse($names));
    }

    /**
     * One grouped aggregate for the whole page, indexed by item and branch.
     */
    private function quantities(array $productIds, $outletFilter): Collection
    {
        return Stock::where('status', Status::ACTIVE)
            ->whereIn('product_id', $productIds)
            ->when(!blank($outletFilter), function ($query) use ($outletFilter) {
                (int) $outletFilter === 0
                    ? $query->whereNull('outlet_id')
                    : $query->where('outlet_id', $outletFilter);
            })
            ->selectRaw('item_type, item_id, outlet_id, SUM(quantity) as quantity')
            ->groupBy('item_type', 'item_id', 'outlet_id')
            ->get()
            ->groupBy(fn($row) => $row->item_type . '|' . $row->item_id);
    }

    private function row($product, string $itemType, $itemId, ?string $variationNames, ?string $sku, Collection $quantities, Collection $outletNames): array
    {
        $rows = $quantities->get($itemType . '|' . $itemId, collect());

        return [
            'product_id'      => $product->id,
            'variation_id'    => $itemType === ProductVariation::class ? $itemId : 0,
            'product_name'    => $product->name,
            'variation_names' => $variationNames,
            'sku'             => $sku,
            'status'          => $product->status,
            'stock'           => (int) $rows->sum('quantity'),
            // can_purchasable = NO means the storefront ignores the count and
            // reports NON_PURCHASE_QUANTITY instead. This screen still shows
            // the real figure - you cannot correct a number you cannot see -
            // and flags it so the difference is not mistaken for a bug.
            'stock_tracked'   => $product->can_purchasable !== Ask::NO,
            'outlet_stocks'   => $this->outletBreakdown($rows, $outletNames),
        ];
    }

    /**
     * One entry per branch, in a fixed order, including the branches holding
     * nothing.
     *
     * Only listing branches that had stock rows left the column blank for any
     * product nothing had ever moved for - which is most of the catalogue -
     * and gave the edit dialog no branches to offer. A zero is information;
     * an empty cell is not.
     */
    private function outletBreakdown(Collection $rows, Collection $outletNames): array
    {
        $byOutlet = $rows->groupBy(fn($row) => $row->outlet_id ?? 0)
            ->map(fn($outletRows) => (int) $outletRows->sum('quantity'));

        $breakdown = [];

        foreach ($outletNames as $outletId => $outletName) {
            $breakdown[] = [
                'outlet_id'   => $outletId,
                'outlet_name' => $outletName,
                'quantity'    => $byOutlet->get($outletId, 0),
            ];
        }

        // The unassigned pool is last, and only shown when it holds something -
        // it is a leftover to be cleared out, not a place to file stock into.
        $unassigned = $byOutlet->get(0, 0);
        if ($unassigned !== 0) {
            $breakdown[] = [
                'outlet_id'   => null,
                'outlet_name' => trans('all.label.unassigned'),
                'quantity'    => $unassigned,
            ];
        }

        return $breakdown;
    }

    public function paginate(
        $items,
        $perPage = 15,
        $page = null,
        $baseUrl = null,
        $options = []
    ) {
        $page = $page ?: (Paginator::resolveCurrentPage() ?: 1);

        $items = $items instanceof Collection ?
            $items : Collection::make($items);

        $lap = new LengthAwarePaginator(
            $items->forPage($page, $perPage),
            $items->count(),
            $perPage,
            $page,
            $options
        );

        if ($baseUrl) {
            $lap->setPath($baseUrl);
        }

        return $lap;
    }
}
