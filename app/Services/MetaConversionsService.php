<?php

namespace App\Services;

use App\Libraries\AppLibrary;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Meta's Conversions API - the same events as the browser pixel, sent from the
 * server.
 *
 * Roughly a quarter to a third of browser events never arrive: ad blockers,
 * iOS tracking prevention, a tab closed mid-request. Those are invisible
 * losses - the ad looks worse than it is, and Meta optimises against worse
 * data. This sends the events again from here, where nothing can block them.
 *
 * Every event carries an `event_id` that matches the browser's, which is how
 * Meta discards the duplicate and keeps one. Values are always priced from the
 * database, never from whatever the browser said, so a crafted request cannot
 * inflate the numbers ads are optimised on.
 *
 * Failure is always silent: an ad platform being down, slow or misconfigured
 * must never affect a customer placing an order.
 */
class MetaConversionsService
{
    public function enabled(): bool
    {
        return !blank($this->pixelId()) && !blank(config('services.meta_pixel.token'));
    }

    public function pixelId(): ?string
    {
        $resolved = \App\Support\MetaPixel::resolve();

        return $resolved['id'];
    }

    /**
     * Everything Meta needs to recognise the person behind the event.
     *
     * Personal details are SHA-256 hashed here, before they leave the server -
     * Meta matches on the hash. `_fbp`/`_fbc` are the pixel's own cookies and
     * are sent as-is by design; they carry the ad click id, which is what ties
     * a purchase back to the ad that produced it.
     */
    public function userData(?User $user, ?Request $request = null, array $address = []): array
    {
        $request = $request ?: request();

        $name  = trim((string) ($user->name ?? ''));
        $space = strpos($name, ' ');

        $data = array_filter([
            'em'          => $this->hash($user->email ?? null),
            'ph'          => $this->hash($this->phone($user->phone ?? null, $user->country_code ?? null)),
            'fn'          => $this->hash($space === false ? $name : substr($name, 0, $space)),
            'ln'          => $this->hash($space === false ? null : substr($name, $space + 1)),
            'ct'          => $this->hash($address['city'] ?? null),
            'st'          => $this->hash($address['state'] ?? null),
            'zp'          => $this->hash(preg_replace('/[^0-9a-z]/', '', strtolower((string) ($address['zip_code'] ?? '')))),
            'country'     => $this->hash($this->country($address['country'] ?? null)),
            'external_id' => $this->hash($user->id ?? null),
        ]);

        // Unhashed by design - these are Meta's own identifiers, not personal data.
        $data['client_ip_address'] = $request?->ip();
        $data['client_user_agent'] = $request?->userAgent();

        if ($fbp = $request?->cookie('_fbp')) {
            $data['fbp'] = $fbp;
        }
        if ($fbc = $request?->cookie('_fbc')) {
            $data['fbc'] = $fbc;
        }

        return array_filter($data);
    }

    /**
     * Queues one event. Dispatched after the response, so a customer never
     * waits on a call to Facebook - the queue runs synchronously on this host.
     */
    public function queue(string $eventName, string $eventId, array $userData, array $customData, ?string $sourceUrl = null): void
    {
        if (!$this->enabled()) {
            return;
        }

        \App\Jobs\SendMetaConversionEvent::dispatch([
            'event_name'       => $eventName,
            'event_id'         => $eventId,
            'event_time'       => time(),
            'event_source_url' => $sourceUrl,
            'action_source'    => 'website',
            'user_data'        => $userData,
            'custom_data'      => $customData,
        ])->afterResponse();
    }

    /** Posts a single event. Called from the job, never from a request. */
    public function send(array $event): void
    {
        if (!$this->enabled()) {
            return;
        }

        $payload = ['data' => [array_filter($event, fn($value) => $value !== null && $value !== [])]];

        if ($code = config('services.meta_pixel.test_code')) {
            $payload['test_event_code'] = $code;
        }

        try {
            $response = Http::timeout(4)->connectTimeout(3)->asJson()->post(
                sprintf(
                    'https://graph.facebook.com/%s/%s/events?access_token=%s',
                    config('services.meta_pixel.api_version', 'v21.0'),
                    $this->pixelId(),
                    urlencode((string) config('services.meta_pixel.token'))
                ),
                $payload
            );

            if ($response->failed()) {
                // Body, not just the status: Meta explains what it rejected,
                // and a silently dropped event is impossible to diagnose later.
                Log::warning('Meta Conversions API rejected an event.', [
                    'event'  => $event['event_name'] ?? null,
                    'status' => $response->status(),
                    'body'   => mb_substr($response->body(), 0, 500),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Meta Conversions API unreachable: ' . $e->getMessage());
        }
    }

    /**
     * What a product is worth right now, priced here rather than taken from
     * the browser - the figure ads optimise on must not be forgeable.
     */
    public function productValue(Product $product): float
    {
        $base = $product->variations()->exists() ? (float) $product->variation_price : (float) $product->selling_price;

        if (AppLibrary::isBetweenDate($product->offer_start_date, $product->offer_end_date)) {
            $base -= ($base / 100) * (float) $product->discount;
        }

        return round($base, 2);
    }

    /** The catalogue's id for a product - see services.meta_pixel.content_id. */
    public function contentId(Product $product): string
    {
        return config('services.meta_pixel.content_id') === 'sku' && !blank($product->sku)
            ? (string) $product->sku
            : (string) $product->id;
    }

    /** Purchase, from the order itself. */
    public function orderCustomData(Order $order): array
    {
        $contents = [];

        foreach ($order->orderProducts as $line) {
            $product = Product::withTrashed()->find($line->product_id);

            if (!$product) {
                continue;
            }

            $contents[] = [
                'id'         => $this->contentId($product),
                'quantity'   => (int) abs($line->quantity),
                'item_price' => round((float) $line->price, 2),
            ];
        }

        return [
            'content_ids'  => array_column($contents, 'id'),
            'content_type' => 'product',
            'contents'     => $contents,
            'num_items'    => array_sum(array_column($contents, 'quantity')),
            'value'        => round((float) $order->total, 2),
            'currency'     => \App\Support\MetaPixel::resolve()['currency'],
            'order_id'     => (string) $order->id,
        ];
    }

    private function hash($value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : hash('sha256', mb_strtolower($value));
    }

    /** Digits with the country code, the form Meta normalises to. */
    private function phone($phone, $callingCode): ?string
    {
        $digits = preg_replace('/[^0-9]/', '', (string) $phone);

        if ($digits === '') {
            return null;
        }

        $code = preg_replace('/[^0-9]/', '', (string) ($callingCode ?: '880')) ?: '880';

        return str_starts_with($digits, $code) ? $digits : $code . ltrim($digits, '0');
    }

    /** Meta wants a two-letter country code. */
    private function country($country): ?string
    {
        $value = mb_strtolower(trim((string) $country));

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) === 2) {
            return $value;
        }

        return $value === 'bangladesh' ? 'bd' : null;
    }
}
