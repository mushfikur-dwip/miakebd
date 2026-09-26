<?php

namespace App\Services;

use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Libraries\AppLibrary;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\MetaPixel;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
 * Nothing is sent during a request. queue() writes one row to `meta_events`
 * and returns; `meta:send-events` posts the pending rows once a minute in a
 * single batch. On this host the queue is synchronous, so an outbound call per
 * page view would hold a PHP process for as long as Facebook took to answer -
 * multiplied by every visitor an ad sends at once.
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
    /** Meta rejects events older than this. */
    public const MAX_AGE_DAYS = 7;

    /** Meta accepts up to 1,000 per request; smaller keeps one request quick. */
    public const BATCH_SIZE = 500;

    public function enabled(): bool
    {
        return !blank($this->pixelId()) && !blank(config('services.meta_pixel.token'));
    }

    public function pixelId(): ?string
    {
        return MetaPixel::resolve()['id'];
    }

    /**
     * Everything Meta needs to recognise the person behind the event.
     *
     * Personal details are normalised the way Meta specifies and SHA-256
     * hashed here, before they leave the server - Meta matches on the hash, so
     * "Dhaka " and "dhaka" must hash alike. `_fbp`/`_fbc` are the pixel's own
     * cookies and are sent as-is by design; they carry the ad click id, which
     * is what ties a purchase back to the ad that produced it.
     */
    public function userData(?User $user, ?Request $request = null, array $address = []): array
    {
        $request = $request ?: request();

        $name  = trim(preg_replace('/\s+/u', ' ', (string) ($user->name ?? '')));
        $space = mb_strpos($name, ' ');

        $data = array_filter([
            'em'          => $this->hash($this->email($user->email ?? null)),
            'ph'          => $this->hash($this->phone($user->phone ?? null, $user->country_code ?? null)),
            'fn'          => $this->hash($this->letters($space === false ? $name : mb_substr($name, 0, $space))),
            'ln'          => $this->hash($this->letters($space === false ? null : mb_substr($name, $space + 1))),
            'ct'          => $this->hash($this->letters($address['city'] ?? null)),
            'st'          => $this->hash($this->letters($address['state'] ?? null)),
            'zp'          => $this->hash(preg_replace('/[^0-9a-z]/', '', strtolower((string) ($address['zip_code'] ?? '')))),
            'country'     => $this->hash($this->country($address['country'] ?? null)),
            'external_id' => $this->hash($user->id ?? null),
        ]);

        // Unhashed by design - these are Meta's own identifiers, not personal data.
        $data['client_ip_address'] = $this->clientIp($request);
        $data['client_user_agent'] = $request?->userAgent();

        if ($fbp = $this->metaCookie($request, '_fbp')) {
            $data['fbp'] = $fbp;
        }
        if ($fbc = $this->metaCookie($request, '_fbc')) {
            $data['fbc'] = $fbc;
        }

        return array_filter($data);
    }

    /**
     * Stores one event for the next batch.
     *
     * `$ready = false` holds it back until release() - an online-payment
     * order's Purchase waits for the payment, while the details it carries are
     * the customer's own, captured now. The event_id is unique, so queueing
     * the same event twice is a no-op.
     *
     * @return bool whether a new event was stored
     */
    public function queue(string $eventName, string $eventId, array $userData, array $customData, ?string $sourceUrl = null, bool $ready = true): bool
    {
        if (!$this->enabled()) {
            return false;
        }

        try {
            $stored = DB::table('meta_events')->insertOrIgnore([
                'event_id'   => $eventId,
                'event_name' => $eventName,
                'payload'    => json_encode([
                    'event_name'       => $eventName,
                    'event_id'         => $eventId,
                    // When it happened, not when the batch leaves.
                    'event_time'       => time(),
                    'event_source_url' => $this->cleanUrl($sourceUrl),
                    'action_source'    => 'website',
                    'user_data'        => $userData,
                    'custom_data'      => $customData,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'ready'      => $ready,
                'created_at' => now(),
            ]) > 0;

            // The scheduler prunes too; this keeps the table bounded even on a
            // server where the cron job has stopped running.
            if (random_int(1, 100) === 1) {
                $this->prune();
            }

            return $stored;
        } catch (\Throwable $e) {
            Log::warning('Could not store a Meta event: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * A shopper became a customer with an account - signed up, or claimed
     * their guest orders. Server-side only: the account exists here, and the
     * event_id is fixed per user, so a retried request still counts once.
     */
    public function completeRegistration(User $user, ?Request $request = null): bool
    {
        return $this->queue(
            'CompleteRegistration',
            'registration-' . $user->id,
            $this->userData($user, $request),
            ['status' => 'completed', 'currency' => MetaPixel::resolve()['currency'], 'value' => 0],
            $request ? rtrim((string) config('app.url'), '/') . '/register' : null
        );
    }

    /**
     * Lets a held event go out with the next batch - the payment went through.
     * Its time becomes now: that is when the sale actually happened.
     */
    public function release(string $eventId): bool
    {
        try {
            $row = DB::table('meta_events')->where('event_id', $eventId)->where('ready', false)->first();

            if (!$row) {
                return false;
            }

            $payload = json_decode($row->payload, true) ?: [];
            $payload['event_time'] = time();

            return DB::table('meta_events')->where('id', $row->id)->where('ready', false)->update([
                'ready'   => true,
                'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]) > 0;
        } catch (\Throwable $e) {
            Log::warning('Could not release a held Meta event: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Posts everything pending, one batch per call. Run by `meta:send-events`.
     *
     * Meta rejects a whole batch when a single event in it is malformed, which
     * would leave the queue stuck behind that event for a week. So a rejected
     * batch is retried one event at a time: the good ones go through, the bad
     * one is logged and dropped. A timeout or a server error on Meta's side is
     * different - those rows stay pending and the next run tries again.
     *
     * @return int the number of events Meta accepted
     */
    public function sendPending(): int
    {
        if (!$this->enabled()) {
            return 0;
        }

        $rows = DB::table('meta_events')
            ->where('ready', true)
            ->whereNull('sent_at')
            ->where('created_at', '>=', now()->subDays(self::MAX_AGE_DAYS))
            ->orderBy('id')
            ->limit(self::BATCH_SIZE)
            ->get(['id', 'payload']);

        if ($rows->isEmpty()) {
            return 0;
        }

        $response = $this->post($rows->map(fn($row) => $this->decode($row->payload))->all());

        if ($response?->successful()) {
            $this->markSent($rows->pluck('id')->all());

            return $rows->count();
        }

        if (!$response?->clientError()) {
            // Meta unreachable or failing: everything waits for the next run.
            return 0;
        }

        $accepted = 0;

        foreach ($rows as $row) {
            $single = $this->post([$this->decode($row->payload)]);

            if ($single?->successful()) {
                $this->markSent([$row->id]);
                $accepted++;
            } elseif ($single?->clientError()) {
                // Meta will never accept this one. Dropped so it cannot block
                // the queue; the body says why, for whoever reads the log.
                $this->markSent([$row->id]);
            }
        }

        return $accepted;
    }

    /** Sent rows after a day; anything older than Meta accepts. */
    public function prune(): void
    {
        DB::table('meta_events')->whereNotNull('sent_at')->where('sent_at', '<', now()->subDay())->delete();
        DB::table('meta_events')->whereNull('sent_at')->where('created_at', '<', now()->subDays(self::MAX_AGE_DAYS))->delete();
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

    /**
     * Whether an order is a sale yet: cash on delivery the moment it is
     * placed, an online payment only once the money has arrived. An abandoned
     * or failed bKash payment is not a conversion, and counting it would teach
     * Meta to find more people who do not pay.
     */
    public function orderIsSale(Order $order): bool
    {
        return (int) $order->payment_method === PaymentGateway::CASH_ON_DELIVERY
            || (int) $order->payment_status === PaymentStatus::PAID;
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
            'content_ids'  => array_values(array_unique(array_column($contents, 'id'))),
            'content_type' => 'product',
            'contents'     => $contents,
            'num_items'    => array_sum(array_column($contents, 'quantity')),
            'value'        => round((float) $order->total, 2),
            'currency'     => MetaPixel::resolve()['currency'],
            'order_id'     => (string) $order->id,
        ];
    }

    private function post(array $events): ?Response
    {
        $payload = [
            'data'         => $events,
            // In the body rather than the URL, so the token never ends up in a
            // proxy or access log.
            'access_token' => (string) config('services.meta_pixel.token'),
        ];

        if ($code = config('services.meta_pixel.test_code')) {
            $payload['test_event_code'] = $code;
        }

        try {
            $response = Http::timeout(10)->connectTimeout(5)->asJson()->post(
                sprintf('https://graph.facebook.com/%s/%s/events', config('services.meta_pixel.api_version', 'v21.0'), $this->pixelId()),
                $payload
            );

            if ($response->failed()) {
                // Body, not just the status: Meta explains what it rejected,
                // and a silently dropped event is impossible to diagnose later.
                Log::warning('Meta Conversions API rejected events.', [
                    'count'  => count($events),
                    'status' => $response->status(),
                    'body'   => mb_substr($response->body(), 0, 500),
                ]);
            }

            return $response;
        } catch (\Throwable $e) {
            Log::warning('Meta Conversions API unreachable: ' . $e->getMessage());

            return null;
        }
    }

    private function markSent(array $ids): void
    {
        DB::table('meta_events')->whereIn('id', $ids)->update(['sent_at' => now()]);
    }

    private function decode(string $payload): array
    {
        $event = json_decode($payload, true) ?: [];

        return array_filter($event, fn($value) => $value !== null && $value !== []);
    }

    /**
     * The page the event happened on, without its query string: a search term
     * or a click id has no business in Meta's copy of the URL, and Meta flags
     * pixels that send personal or sensitive details in URL parameters.
     */
    private function cleanUrl(?string $url): ?string
    {
        if (blank($url)) {
            return null;
        }

        return strtok((string) $url, '?#') ?: null;
    }

    /**
     * The shopper's own address, for Meta's matching.
     *
     * The site sits behind Hostinger's CDN and TrustProxies trusts no proxy,
     * so $request->ip() may be the CDN edge the request came through - the
     * same address for thousands of shoppers, which Meta can match to nobody.
     * The CDN names the visitor first in X-Forwarded-For. Trusting that header
     * app-wide would let anyone dodge the per-IP rate limits by writing it
     * themselves; here the worst a forged value can do is spoil the match of
     * the forger's own event, so it is read for this one purpose only.
     */
    private function clientIp(?Request $request): ?string
    {
        if (!$request) {
            return null;
        }

        foreach (explode(',', (string) $request->headers->get('X-Forwarded-For', '')) as $candidate) {
            $candidate = trim($candidate);

            if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $candidate;
            }
        }

        return $request->ip();
    }

    /**
     * The pixel's cookie, when it looks like one. The cookie is set outside
     * Laravel's encryption on purpose (the pixel has to read it), so it is
     * taken from the raw header value and shape-checked before use.
     */
    private function metaCookie(?Request $request, string $name): ?string
    {
        $value = (string) ($request?->cookies->get($name) ?? '');

        return preg_match('/^fb\.\d\.\d{10,13}\.[A-Za-z0-9_.\-]{1,500}$/', $value) ? $value : null;
    }

    private function hash($value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : hash('sha256', $value);
    }

    private function email($email): ?string
    {
        $email = mb_strtolower(trim((string) $email));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /** Lowercase letters only - Meta's rule for names, city and district. */
    private function letters($value): ?string
    {
        $value = preg_replace('/[^\p{L}]/u', '', mb_strtolower(trim((string) $value)));

        return $value === '' ? null : $value;
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
