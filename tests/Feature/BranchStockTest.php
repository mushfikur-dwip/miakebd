<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\Status;
use App\Http\Requests\PaginateRequest;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Stock;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Exercises branch-wise stock against a real database.
 *
 * The stock screen is assembled from three queries and a pile of in-PHP
 * grouping, none of which any test covered - so "the stock page is wrong" had
 * no way to be answered except by reading the code and guessing. These pin the
 * behaviour that matters: every product appears whether or not it has ever
 * moved, each branch's number is its own, and the total the storefront shows
 * is the sum across branches.
 */
class BranchStockTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $name, string $sku): Product
    {
        return Product::create([
            'name'            => $name,
            'slug'            => str($name)->slug()->value(),
            'sku'             => $sku,
            'status'          => Status::ACTIVE,
            'can_purchasable' => Ask::YES,
            'buying_price'    => 100,
            'selling_price'   => 150,
            'variation_price' => 150,
        ]);
    }

    private function outlet(string $name): Outlet
    {
        return Outlet::create([
            'name'     => $name,
            'email'    => str($name)->slug()->value() . '@example.test',
            'phone'    => '01700000000',
            'city'     => 'Dhaka',
            'state'    => 'Dhaka',
            'zip_code' => '1212',
            'address'  => $name . ', Dhaka',
            'status'   => Status::ACTIVE,
        ]);
    }

    private function move(Product $product, ?Outlet $outlet, int $quantity): void
    {
        Stock::create([
            'product_id' => $product->id,
            'outlet_id'  => $outlet?->id,
            'model_type' => Product::class,
            'model_id'   => $product->id,
            'item_type'  => Product::class,
            'item_id'    => $product->id,
            'quantity'   => $quantity,
            'status'     => Status::ACTIVE,
        ]);
    }

    private function rows(array $query = []): array
    {
        $request = PaginateRequest::create('/api/admin/stock', 'GET', $query + ['paginate' => 0]);
        $request->setContainer(app())->validateResolved();

        return app(StockService::class)->list($request);
    }

    public function test_a_product_that_has_never_moved_still_appears_with_zero(): void
    {
        $this->product('Never Purchased', 'SKU-NEVER');

        $rows = $this->rows();

        $this->assertCount(1, $rows);
        $this->assertSame('Never Purchased', $rows[0]['product_name']);
        $this->assertSame(0, $rows[0]['stock']);
    }

    public function test_quantity_is_the_sum_across_every_branch(): void
    {
        $product = $this->product('Sunscreen', 'SKU-SUN');
        $gulshan = $this->outlet('Gulshan');
        $dhanmondi = $this->outlet('Dhanmondi');

        $this->move($product, $gulshan, 12);
        $this->move($product, $dhanmondi, 5);
        $this->move($product, null, 3);   // unassigned pool
        $this->move($product, $gulshan, -2); // a POS sale at Gulshan

        $rows = $this->rows();

        // 12 + 5 + 3 - 2. This is the figure the storefront shows.
        $this->assertSame(18, $rows[0]['stock']);

        $byBranch = collect($rows[0]['outlet_stocks'])->pluck('quantity', 'outlet_name')->all();
        $this->assertSame(10, $byBranch['Gulshan']);
        $this->assertSame(5, $byBranch['Dhanmondi']);
        $this->assertSame(3, $byBranch['Unassigned']);
    }

    public function test_a_branch_holding_nothing_is_still_listed_as_zero(): void
    {
        $product = $this->product('Face Wash', 'SKU-FACE');
        $gulshan = $this->outlet('Gulshan');
        $this->outlet('Uttara');

        $this->move($product, $gulshan, 4);

        $byBranch = collect($this->rows()[0]['outlet_stocks'])->pluck('quantity', 'outlet_name')->all();

        $this->assertSame(4, $byBranch['Gulshan']);
        $this->assertArrayHasKey('Uttara', $byBranch);
        $this->assertSame(0, $byBranch['Uttara']);
    }

    public function test_filtering_by_branch_reports_only_that_branch(): void
    {
        $product = $this->product('Serum', 'SKU-SERUM');
        $gulshan = $this->outlet('Gulshan');
        $dhanmondi = $this->outlet('Dhanmondi');

        $this->move($product, $gulshan, 7);
        $this->move($product, $dhanmondi, 9);

        $rows = $this->rows(['outlet_id' => $gulshan->id]);

        $this->assertSame(7, $rows[0]['stock']);
        $this->assertCount(1, $rows[0]['outlet_stocks']);
        $this->assertSame('Gulshan', $rows[0]['outlet_stocks'][0]['outlet_name']);
    }

    public function test_zero_selects_the_unassigned_pool(): void
    {
        $product = $this->product('Toner', 'SKU-TONER');
        $gulshan = $this->outlet('Gulshan');

        $this->move($product, $gulshan, 6);
        $this->move($product, null, 2);

        $rows = $this->rows(['outlet_id' => 0]);

        $this->assertSame(2, $rows[0]['stock']);
    }

    public function test_sku_search_finds_the_product_a_barcode_scan_resolves_to(): void
    {
        $this->product('Sunscreen', 'SKU-SUN-01');
        $this->product('Lipstick', 'SKU-LIP-02');

        $rows = $this->rows(['sku' => 'SKU-LIP-02']);

        $this->assertCount(1, $rows);
        $this->assertSame('Lipstick', $rows[0]['product_name']);
    }

    /**
     * The edit dialog on the stock screen: type the number each branch should
     * have, save, and that becomes the branch's stock.
     */
    private function setStock(Product $product, array $perBranch): void
    {
        $request = \App\Http\Requests\StockItemUpdateRequest::create('/api/admin/stock/update-item', 'POST', [
            'product_id'   => $product->id,
            'variation_id' => 0,
            'stocks'       => json_encode($perBranch),
        ]);
        $request->setContainer(app())->validateResolved();

        app(\App\Services\StockAdjustmentService::class)->updateItemStock($request);
    }

    public function test_setting_a_branch_count_makes_it_exactly_that(): void
    {
        $product = $this->product('Cleanser', 'SKU-CLEAN');
        $gulshan = $this->outlet('Gulshan');

        $this->move($product, $gulshan, 3);

        $this->setStock($product, [['outlet_id' => $gulshan->id, 'quantity' => 25]]);

        $this->assertSame(25, $this->rows()[0]['stock']);
    }

    public function test_setting_the_same_number_again_writes_nothing(): void
    {
        $product = $this->product('Mask', 'SKU-MASK');
        $gulshan = $this->outlet('Gulshan');

        $this->move($product, $gulshan, 8);
        $before = Stock::count();

        $this->setStock($product, [['outlet_id' => $gulshan->id, 'quantity' => 8]]);

        // A no-op save must not litter the ledger with zero-quantity rows.
        $this->assertSame($before, Stock::count());
        $this->assertSame(8, $this->rows()[0]['stock']);
    }

    public function test_a_branch_can_be_set_to_zero(): void
    {
        $product = $this->product('Scrub', 'SKU-SCRUB');
        $gulshan = $this->outlet('Gulshan');

        $this->move($product, $gulshan, 14);
        $this->setStock($product, [['outlet_id' => $gulshan->id, 'quantity' => 0]]);

        $this->assertSame(0, $this->rows()[0]['stock']);
    }

    public function test_setting_one_branch_leaves_the_others_alone(): void
    {
        $product = $this->product('Balm', 'SKU-BALM');
        $gulshan = $this->outlet('Gulshan');
        $dhanmondi = $this->outlet('Dhanmondi');

        $this->move($product, $gulshan, 10);
        $this->move($product, $dhanmondi, 4);

        $this->setStock($product, [['outlet_id' => $gulshan->id, 'quantity' => 1]]);

        $byBranch = collect($this->rows()[0]['outlet_stocks'])->pluck('quantity', 'outlet_name')->all();
        $this->assertSame(1, $byBranch['Gulshan']);
        $this->assertSame(4, $byBranch['Dhanmondi']);
        $this->assertSame(5, $this->rows()[0]['stock']);
    }

    public function test_a_product_not_stock_controlled_still_reports_its_real_count(): void
    {
        $product = $this->product('Gift Card', 'SKU-GIFT');
        $product->update(['can_purchasable' => Ask::NO]);
        $gulshan = $this->outlet('Gulshan');

        $this->move($product, $gulshan, 11);

        $rows = $this->rows();

        // The storefront ignores this number for such products, but the stock
        // screen has to show it or it cannot be corrected.
        $this->assertSame(11, $rows[0]['stock']);
        $this->assertFalse($rows[0]['stock_tracked']);
    }
}
