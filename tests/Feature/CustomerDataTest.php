<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\Role as EnumRole;
use App\Enums\Status;
use App\Models\Address;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\ReturnAndRefund;
use App\Models\ReturnAndRefundProduct;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * What one customer can do to another customer's data, and what an upload can
 * do to the site. Addresses, reviews and return requests were all reachable by
 * id with no owner check, and review/return photos skipped validation because
 * their rule keys ('images[]', 'image[]') matched nothing.
 */
class CustomerDataTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Admin', 'Customer', 'Manager', 'POS Operator', 'Stuff'] as $name) {
            Role::create(['name' => $name, 'guard_name' => 'sanctum']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->product = Product::create([
            'name' => 'Serum', 'slug' => 'serum', 'sku' => 'SERUM1', 'status' => Status::ACTIVE, 'can_purchasable' => Ask::YES,
            'buying_price' => 100, 'selling_price' => 500, 'variation_price' => 500,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/public/_security_test'));

        parent::tearDown();
    }

    private function customer(string $name): User
    {
        $user = User::create([
            'name' => $name, 'username' => strtolower($name), 'email' => strtolower($name) . '@example.com',
            'password' => bcrypt('secret123'), 'phone' => '017' . random_int(10000000, 99999999), 'country_code' => '+880',
            'is_guest' => Ask::NO, 'status' => Status::ACTIVE,
        ]);
        $user->assignRole(EnumRole::CUSTOMER);

        return $user;
    }

    private function address(User $owner): Address
    {
        return Address::create([
            'user_id' => $owner->id, 'full_name' => $owner->name, 'phone' => '01700000000', 'country_code' => '+880',
            'country' => 'Bangladesh', 'state' => 'Dhaka', 'address' => 'House 1',
        ]);
    }

    /** A delivered order for two of $this->product at 500, with its stock row. */
    private function deliveredOrder(User $owner, int $status = OrderStatus::DELIVERED): Order
    {
        $order = Order::create([
            'user_id' => $owner->id, 'order_type' => OrderType::DELIVERY, 'subtotal' => 1000, 'total' => 1095,
            'discount' => 0, 'tax' => 0, 'shipping_charge' => 95, 'status' => $status, 'payment_status' => 5,
            'source' => 5, 'payment_method' => 2, 'active' => Ask::YES, 'order_datetime' => now(),
        ]);
        $order->update(['order_serial_no' => 'SN' . $order->id]);

        Stock::create([
            'product_id' => $this->product->id, 'model_type' => Order::class, 'model_id' => $order->id,
            'item_type' => Product::class, 'item_id' => $this->product->id, 'variation_names' => '', 'sku' => 'SERUM1',
            'price' => 500, 'quantity' => -2, 'discount' => 0, 'tax' => 0, 'subtotal' => 1000, 'total' => 1000,
            'status' => Status::ACTIVE,
        ]);

        return $order;
    }

    // --- addresses --------------------------------------------------------

    public function test_a_customer_cannot_read_another_customers_address(): void
    {
        $victim = $this->address($this->customer('Victim'));
        Sanctum::actingAs($this->customer('Mallory'));

        $this->getJson("/api/frontend/address/show/{$victim->id}")->assertNotFound();
    }

    public function test_a_customer_cannot_rewrite_or_delete_another_customers_address(): void
    {
        $victim = $this->address($this->customer('Victim'));
        Sanctum::actingAs($this->customer('Mallory'));

        $this->putJson("/api/frontend/address/{$victim->id}", [
            'full_name' => 'Mallory', 'country_code' => '+880', 'phone' => '01799999999',
            'country' => 'Bangladesh', 'state' => 'Dhaka', 'address' => 'Somewhere else',
        ])->assertNotFound();
        $this->deleteJson("/api/frontend/address/{$victim->id}")->assertNotFound();

        $this->assertSame('House 1', $victim->fresh()->address);
    }

    public function test_a_customer_can_still_read_their_own_address(): void
    {
        $owner = $this->customer('Rima');
        $mine  = $this->address($owner);
        Sanctum::actingAs($owner);

        $this->getJson("/api/frontend/address/show/{$mine->id}")->assertOk();
    }

    // --- reviews ----------------------------------------------------------

    public function test_a_review_photo_that_is_really_html_is_refused(): void
    {
        $owner = $this->customer('Rima');
        $this->deliveredOrder($owner);
        Sanctum::actingAs($owner);

        $this->post('/api/frontend/product-review', [
            'product_id' => $this->product->id, 'star' => 5, 'review' => 'Great',
            'images'     => [UploadedFile::fake()->createWithContent('photo.html', '<script>alert(1)</script>')],
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertSame(0, ProductReview::count());
    }

    public function test_a_product_cannot_be_reviewed_without_receiving_it(): void
    {
        Sanctum::actingAs($this->customer('Stranger'));

        $this->postJson('/api/frontend/product-review', [
            'product_id' => $this->product->id, 'star' => 1, 'review' => 'Terrible',
        ])->assertStatus(422);

        $this->assertSame(0, ProductReview::count());
    }

    public function test_a_review_cannot_carry_more_than_five_stars(): void
    {
        $owner = $this->customer('Rima');
        $this->deliveredOrder($owner);
        Sanctum::actingAs($owner);

        $this->postJson('/api/frontend/product-review', [
            'product_id' => $this->product->id, 'star' => 1000, 'review' => 'Best',
        ])->assertStatus(422);
    }

    public function test_a_delivered_product_can_be_reviewed_with_a_photo(): void
    {
        Storage::fake('public');
        $owner = $this->customer('Rima');
        $this->deliveredOrder($owner);
        Sanctum::actingAs($owner);

        $this->post('/api/frontend/product-review', [
            'product_id' => $this->product->id, 'star' => 5, 'review' => 'Great',
            'images'     => [UploadedFile::fake()->image('photo.png', 20, 20)],
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame(1, ProductReview::sole()->getMedia('product-review')->count());
    }

    public function test_a_customer_cannot_edit_another_customers_review(): void
    {
        $author = $this->customer('Author');
        $review = ProductReview::create(['user_id' => $author->id, 'product_id' => $this->product->id, 'star' => 5, 'review' => 'Lovely']);
        Sanctum::actingAs($this->customer('Mallory'));

        $this->postJson("/api/frontend/product-review/{$review->id}", [
            'product_id' => $this->product->id, 'star' => 1, 'review' => 'Awful',
        ])->assertNotFound();

        $this->assertSame('Lovely', $review->fresh()->review);
    }

    // --- return requests --------------------------------------------------

    private function returnRequest(Order $order, array $productOverrides = [])
    {
        $reason = \App\Models\ReturnReason::firstOrCreate(['title' => 'Damaged'], ['status' => Status::ACTIVE]);

        return $this->postJson("/api/frontend/return-order/request/{$order->id}", [
            'return_reason_id' => $reason->id,
            'note'             => 'Damaged',
            'order_id'         => $order->id,
            'order_serial_no'  => $order->order_serial_no,
            'products'         => json_encode([$productOverrides + [
                'product_id' => $this->product->id, 'has_variation' => false, 'variation_id' => 0,
                'quantity' => 1, 'order_quantity' => 2, 'price' => 500, 'total' => 1000, 'tax' => 0, 'return_price' => 500,
            ]]),
        ]);
    }

    public function test_a_return_cannot_be_filed_against_someone_elses_order(): void
    {
        $order = $this->deliveredOrder($this->customer('Victim'));
        Sanctum::actingAs($this->customer('Mallory'));

        $this->returnRequest($order)->assertStatus(422);

        $this->assertSame(0, ReturnAndRefund::count());
    }

    public function test_a_return_needs_a_delivered_order(): void
    {
        $owner = $this->customer('Rima');
        $order = $this->deliveredOrder($owner, OrderStatus::PENDING);
        Sanctum::actingAs($owner);

        $this->returnRequest($order)->assertStatus(422);
    }

    public function test_the_refund_is_priced_from_the_order_not_the_browser(): void
    {
        $owner = $this->customer('Rima');
        $order = $this->deliveredOrder($owner);
        Sanctum::actingAs($owner);

        $this->returnRequest($order, ['return_price' => 99999, 'price' => 99999])->assertCreated();

        $line = ReturnAndRefundProduct::sole();
        $this->assertEquals(500, (float) $line->return_price);
        $this->assertEquals(500, (float) $line->price);
    }

    public function test_a_return_cannot_exceed_the_quantity_ordered(): void
    {
        $owner = $this->customer('Rima');
        $order = $this->deliveredOrder($owner);
        Sanctum::actingAs($owner);

        $this->returnRequest($order, ['quantity' => 50])->assertStatus(422);
    }

    // --- /storage ---------------------------------------------------------

    private function storeFile(string $name, string $contents): string
    {
        File::ensureDirectoryExists(storage_path('app/public/_security_test'));
        File::put(storage_path('app/public/_security_test/' . $name), $contents);

        return '/storage/_security_test/' . $name;
    }

    public function test_storage_will_not_serve_html(): void
    {
        $this->get($this->storeFile('page.png', '<html><script>alert(document.domain)</script></html>'))->assertNotFound();
    }

    public function test_storage_will_not_serve_a_service_account_key(): void
    {
        $this->get($this->storeFile('service-account-file.json', '{"type":"service_account","private_key":"x"}'))->assertNotFound();
    }

    public function test_storage_serves_images_inside_a_sandbox(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');

        $response = $this->get($this->storeFile('dot.png', $png));

        $response->assertOk();
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('sandbox', (string) $response->headers->get('Content-Security-Policy'));
    }
}
