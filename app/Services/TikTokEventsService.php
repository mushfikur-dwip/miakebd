<?php

namespace App\Services;

use App\Http\Middleware\CaptureTikTokClickId;
use App\Models\User;
use App\Support\ClientIp;
use App\Support\TikTokPixel;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * TikTok's Events API - the browser pixel's events sent again from the server,
 * where no ad blocker or iOS setting can stop them.
 *
 * Built the same way as MetaConversionsService, and for the same reasons: a
 * request only stores a row in `tiktok_events`; `tiktok:send-events` posts the
 * pending rows once a minute; every event carries the browser's event_id so
 * TikTok keeps one copy; values are read from the database, never taken from
 * the browser; and every failure is silent.
 *
 * Two things differ from Meta. TikTok answers HTTP 200 even when it refuses a
 * request, with the reason in a non-zero `code`. And it wants a phone number
 * hashed in E.164 form, plus sign included, so Meta's hashes cannot be reused.
 */
class TikTokEventsService
{
    /** Rows older than this are pruned unsent, as Meta's are. */
    public const MAX_AGE_DAYS = 7;

    /** Small on purpose: TikTok's per-request limit is not worth discovering in production. */
    public const BATCH_SIZE = 50;

    /** Up to 500 events a minute; anything beyond waits for the next run. */
    public const BATCHES_PER_RUN = 10;

    private const ENDPOINT = 'https://business-api.tiktok.com/open_api/v1.3/event/track/';

    private const ACCEPTED    = 'accepted';
    private const REFUSED     = 'refused';
    private const UNAVAILABLE = 'unavailable';

    public function enabled(): bool
    {
        return !blank($this->pixelId()) && !blank(config('services.tiktok_pixel.token'));
    }

    public function pixelId(): ?string
    {
        return TikTokPixel::configuredId();
    }

    /**
     * Everything TikTok needs to recognise the person behind the event.
     * Personal details are normalised and SHA-256 hashed here, before they
     * leave the server; the click id, browser id, IP and user agent are
     * TikTok's matching signals and go as they are.
     */
    public function userData(?User $user, ?Request $request = null): array
    {
        $request = $request ?: request();

        return array_filter([
            'email'       => $this->hash($this->email($user->email ?? null)),
            'phone'       => $this->hash($this->phone($user->phone ?? null, $user->country_code ?? null)),
            'external_id' => $this->hash($user->id ?? null),
            'ttclid'      => $this->cookie($request, CaptureTikTokClickId::COOKIE, CaptureTikTokClickId::PATTERN),
            // Set by the pixel script itself; excluded from cookie encryption.
            'ttp'         => $this->cookie($request, '_ttp', '/^[A-Za-z0-9_.\-]{10,100}$/'),
            'ip'          => ClientIp::of($request),
            'user_agent'  => $request?->userAgent(),
        ]);
    }

    /**
     * Meta-shaped custom data - what TrackingController and
     * MetaConversionsService::orderCustomData() build - in TikTok's shape.
     * Only the field names change; the ids and prices are the server's own.
     */
    public function properties(array $customData): array
    {
        $contents = array_map(fn(array $line) => [
            'content_id'   => (string) $line['id'],
            'content_type' => 'product',
            'quantity'     => (int) $line['quantity'],
            'price'        => $line['item_price'],
        ], $customData['contents'] ?? []);

        return array_filter([
            'contents'     => $contents,
            'content_type' => 'product',
            'value'        => $customData['value'] ?? null,
            'currency'     => $customData['currency'] ?? null,
            'order_id'     => $customData['order_id'] ?? null,
        ], fn($value) => $value !== null && $value !== []);
    }

    /**
     * Stores one event for the next batch. `$ready = false` holds it until
     * release(); the event_id is unique, so storing it twice is a no-op.
     *
     * @return bool whether a new event was stored
     */
    public function queue(string $event, string $eventId, array $user, array $properties, ?string $url = null, bool $ready = true): bool
    {
        if (!$this->enabled()) {
            return false;
        }

        try {
            $stored = DB::table('tiktok_events')->insertOrIgnore([
                'event_id'   => $eventId,
                'event_name' => $event,
                'payload'    => json_encode([
                    'event'      => $event,
                    'event_id'   => $eventId,
                    // When it happened, not when the batch leaves.
                    'event_time' => time(),
                    'user'       => $user,
                    'properties' => $properties,
                    'page'       => array_filter(['url' => $this->cleanUrl($url)]),
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'ready'      => $ready,
                'created_at' => now(),
            ]) > 0;

            // Keeps the table bounded even when the cron has stopped.
            if (random_int(1, 100) === 1) {
                $this->prune();
            }

            return $stored;
        } catch (\Throwable $e) {
            Log::warning('Could not store a TikTok event: ' . $e->getMessage());

            return false;
        }
    }

    /** Server-side only, fixed id per user - see MetaConversionsService::completeRegistration(). */
    public function completeRegistration(User $user, ?Request $request = null): bool
    {
        return $this->queue(
            'CompleteRegistration',
            'registration-' . $user->id,
            $this->userData($user, $request),
            [],
            $request ? rtrim((string) config('app.url'), '/') . '/register' : null
        );
    }

    /** Lets a held Purchase go out - the payment went through, so its time is now. */
    public function release(string $eventId): bool
    {
        try {
            $row = DB::table('tiktok_events')->where('event_id', $eventId)->where('ready', false)->first();

            if (!$row) {
                return false;
            }

            $payload = json_decode($row->payload, true) ?: [];
            $payload['event_time'] = time();

            return DB::table('tiktok_events')->where('id', $row->id)->where('ready', false)->update([
                'ready'   => true,
                'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]) > 0;
        } catch (\Throwable $e) {
            Log::warning('Could not release a held TikTok event: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Posts what is pending, batch by batch, until the queue is empty or a
     * batch does not go through. Run by `tiktok:send-events`.
     *
     * @return int the number of events TikTok accepted
     */
    public function sendPending(): int
    {
        if (!$this->enabled()) {
            return 0;
        }

        $accepted = 0;

        for ($batch = 0; $batch < self::BATCHES_PER_RUN; $batch++) {
            $rows = DB::table('tiktok_events')
                ->where('ready', true)
                ->whereNull('sent_at')
                ->where('created_at', '>=', now()->subDays(self::MAX_AGE_DAYS))
                ->orderBy('id')
                ->limit(self::BATCH_SIZE)
                ->get(['id', 'payload']);

            if ($rows->isEmpty()) {
                break;
            }

            $result = $this->post($rows->map(fn($row) => $this->decode($row->payload))->all());

            if ($result === self::ACCEPTED) {
                $this->markSent($rows->pluck('id')->all());
                $accepted += $rows->count();

                continue;
            }

            if ($result === self::REFUSED && $rows->count() > 1) {
                $accepted += $this->sendOneByOne($rows);
            }

            break;
        }

        return $accepted;
    }

    /**
     * After a refused batch: which events are at fault?
     *
     * TikTok refuses a whole batch over one bad event. Sent singly, the good
     * ones go through and the bad one is dropped so it cannot block the queue.
     * But when nothing at all is accepted, the fault is the request, not the
     * events - a wrong or revoked token - so everything is kept for when it is
     * fixed, and two refusals are enough to stop calling TikTok once per event.
     */
    private function sendOneByOne(Collection $rows): int
    {
        $accepted = 0;
        $refused  = [];

        foreach ($rows as $row) {
            $result = $this->post([$this->decode($row->payload)]);

            if ($result === self::ACCEPTED) {
                $this->markSent([$row->id]);
                $accepted++;
            } elseif ($result === self::REFUSED) {
                $refused[] = $row->id;

                if ($accepted === 0 && count($refused) >= 2) {
                    return 0;
                }
            } else {
                // TikTok went down mid-way: the rest waits for the next run.
                break;
            }
        }

        if ($accepted > 0) {
            $this->markSent($refused);
        }

        return $accepted;
    }

    /** Sent rows after a day; unsent ones after MAX_AGE_DAYS. */
    public function prune(): void
    {
        DB::table('tiktok_events')->whereNotNull('sent_at')->where('sent_at', '<', now()->subDay())->delete();
        DB::table('tiktok_events')->whereNull('sent_at')->where('created_at', '<', now()->subDays(self::MAX_AGE_DAYS))->delete();
    }

    private function post(array $events): string
    {
        $body = [
            'event_source'    => 'web',
            'event_source_id' => $this->pixelId(),
            'data'            => $events,
        ];

        if ($code = config('services.tiktok_pixel.test_code')) {
            $body['test_event_code'] = $code;
        }

        try {
            $response = Http::timeout(10)->connectTimeout(5)->asJson()
                // A header, not the body or the URL, as TikTok specifies.
                ->withHeaders(['Access-Token' => (string) config('services.tiktok_pixel.token')])
                ->post(self::ENDPOINT, $body);
        } catch (\Throwable $e) {
            Log::warning('TikTok Events API unreachable: ' . $e->getMessage());

            return self::UNAVAILABLE;
        }

        $code = $response->json('code');

        if ($response->successful() && $code === 0) {
            return self::ACCEPTED;
        }

        // The body says why; a silently dropped event is impossible to diagnose later.
        Log::warning('TikTok Events API did not accept events.', [
            'count'  => count($events),
            'status' => $response->status(),
            'body'   => mb_substr($response->body(), 0, 500),
        ]);

        // TikTok's own trouble, or too busy: nothing wrong with the events.
        if ($response->serverError() || $response->status() === 429 || (is_int($code) && $code >= 50000)) {
            return self::UNAVAILABLE;
        }

        return self::REFUSED;
    }

    private function markSent(array $ids): void
    {
        DB::table('tiktok_events')->whereIn('id', $ids)->update(['sent_at' => now()]);
    }

    private function decode(string $payload): array
    {
        $event = json_decode($payload, true) ?: [];

        return array_filter($event, fn($value) => $value !== null && $value !== []);
    }

    /** Without its query string: click ids and search terms stay out of TikTok's copy. */
    private function cleanUrl(?string $url): ?string
    {
        return blank($url) ? null : (strtok((string) $url, '?#') ?: null);
    }

    /** Read raw (these are not encrypted) and shape-checked before use. */
    private function cookie(?Request $request, string $name, string $pattern): ?string
    {
        $value = (string) ($request?->cookies->get($name) ?? '');

        return preg_match($pattern, $value) ? $value : null;
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

    /** E.164: plus, country code, number - "+8801711111111". */
    private function phone($phone, $callingCode): ?string
    {
        $digits = preg_replace('/[^0-9]/', '', (string) $phone);

        if ($digits === '') {
            return null;
        }

        $code = preg_replace('/[^0-9]/', '', (string) ($callingCode ?: '880')) ?: '880';

        return '+' . (str_starts_with($digits, $code) ? $digits : $code . ltrim($digits, '0'));
    }
}
