<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Http\Requests\TrackEventRequest;
use App\Models\Product;
use App\Services\MetaConversionsService;
use App\Services\TikTokEventsService;
use App\Support\MetaPixel;
use Illuminate\Http\Response;

/**
 * The storefront's own endpoint for mirroring pixel events to Meta's
 * Conversions API and TikTok's Events API.
 *
 * A blocked browser cannot reach facebook.net, but it can still reach this
 * site - so the event survives. Each one carries the same event_id as the
 * browser's attempt, and Meta keeps whichever arrives first.
 *
 * Everything that decides money - prices, quantities in the total, the
 * currency - is read from the database here. The request only says which
 * products were involved.
 */
class TrackingController extends Controller
{
    public function __construct(private MetaConversionsService $meta, private TikTokEventsService $tiktok)
    {
    }

    public function store(TrackEventRequest $request): Response
    {
        // No token configured: the browser pixels are on their own, which is
        // exactly the behaviour before this endpoint existed.
        if (!$this->meta->enabled() && !$this->tiktok->enabled()) {
            return response()->noContent();
        }

        try {
            $event = $request->validated('event');
            // Basket events carry the cart; the rest are about one product.
            $data  = in_array($event, ['InitiateCheckout', 'AddPaymentInfo'], true)
                ? $this->checkoutData($request->validated('contents', []))
                : $this->productData($request);

            if ($data === null) {
                return response()->noContent();
            }

            $user = auth('sanctum')->user();
            $url  = $this->sourceUrl($request->validated('source_url'));

            // Each is a no-op when its own token is missing.
            $this->meta->queue($event, $request->validated('event_id'), $this->meta->userData($user, $request, $this->address()), $data, $url);
            $this->tiktok->queue($event, $request->validated('event_id'), $this->tiktok->userData($user, $request), $this->tiktok->properties($data), $url);
        } catch (\Throwable $e) {
            // Tracking is never worth a visible error.
        }

        return response()->noContent();
    }

    private function productData(TrackEventRequest $request): ?array
    {
        $product = Product::find($request->validated('product_id'));

        if (!$product) {
            return null;
        }

        $quantity = (int) ($request->validated('quantity') ?? 1);
        $price    = $this->meta->productValue($product);
        $id       = $this->meta->contentId($product);

        // No content_name: product names here read "acne", "salicylic",
        // "eczema", and Meta restricts pixels it judges to be sending health
        // information. The catalogue feed carries the names instead, matched
        // on the id - so the ads lose nothing.
        return [
            'content_ids'  => [$id],
            'content_type' => 'product',
            'contents'     => [['id' => $id, 'quantity' => $quantity, 'item_price' => $price]],
            'value'        => round($price * $quantity, 2),
            'currency'     => MetaPixel::resolve()['currency'],
        ];
    }

    private function checkoutData(array $lines): ?array
    {
        $contents = [];

        foreach ($lines as $line) {
            $product = Product::find($line['id'] ?? 0);

            if (!$product) {
                continue;
            }

            $contents[] = [
                'id'         => $this->meta->contentId($product),
                'quantity'   => (int) $line['quantity'],
                'item_price' => $this->meta->productValue($product),
            ];
        }

        if (blank($contents)) {
            return null;
        }

        return [
            'content_ids'  => array_values(array_unique(array_column($contents, 'id'))),
            'content_type' => 'product',
            'contents'     => $contents,
            'num_items'    => array_sum(array_column($contents, 'quantity')),
            'value'        => round(array_sum(array_map(fn($c) => $c['item_price'] * $c['quantity'], $contents)), 2),
            'currency'     => MetaPixel::resolve()['currency'],
        ];
    }

    /** The customer's delivery address, when they are signed in and have one. */
    private function address(): array
    {
        $user = auth('sanctum')->user();

        if (!$user) {
            return [];
        }

        $address = \App\Models\Address::where('user_id', $user->id)->latest('id')->first();

        return $address ? [
            'city'     => $address->city,
            'state'    => $address->state,
            'zip_code' => $address->zip_code,
            'country'  => $address->country,
        ] : [];
    }

    /** Only ever this shop's own URLs, never whatever the request claimed. */
    private function sourceUrl(?string $url): string
    {
        $base = rtrim((string) config('app.url'), '/');

        return $url && str_starts_with($url, $base) ? $url : $base;
    }
}
