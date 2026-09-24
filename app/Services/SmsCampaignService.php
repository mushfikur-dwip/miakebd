<?php

namespace App\Services;

use App\Enums\Role as EnumRole;
use App\Enums\SmsCampaignStatus;
use App\Enums\SmsRecipientStatus;
use App\Models\SmsCampaign;
use App\Models\SmsCampaignRecipient;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Promotional SMS to the whole customer list.
 *
 * Sending runs in batches driven by the admin page rather than a queue worker,
 * because QUEUE_CONNECTION is `sync` on this host - a dispatched job would run
 * inline inside the HTTP request and a few thousand gateway calls would hit the
 * execution timeout with no record of how far it got.
 *
 * Each batch is its own short request, and progress lives in the database, so
 * a closed tab or a dropped connection costs at most one batch: reopening the
 * campaign resumes exactly where it stopped, and nobody is texted twice.
 */
class SmsCampaignService
{
    /** Kept small so a batch finishes well inside any execution-time limit. */
    public const BATCH_SIZE = 20;

    public const PLACEHOLDER = '{name}';

    /**
     * Customers who could receive a campaign.
     *
     * Guest-checkout rows are included - they are real buyers - but several of
     * them can share one phone number, so the list is deduplicated by phone
     * before it is stored. Without that one person gets the same promo once per
     * guest order they ever placed.
     */
    public function recipientQuery()
    {
        return User::role(EnumRole::CUSTOMER)
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->select('id', 'name', 'country_code', 'phone')
            ->orderBy('id');
    }

    /** How many distinct phone numbers a campaign would reach. */
    public function audienceSize(): int
    {
        $seen = [];

        $this->recipientQuery()->chunk(1000, function ($users) use (&$seen) {
            foreach ($users as $user) {
                $seen[$this->normalisePhone($user->country_code, $user->phone)] = true;
            }
        });

        return count($seen);
    }

    /**
     * Creates the campaign and freezes its recipient list.
     *
     * The list is a snapshot on purpose: what the admin was shown as the cost
     * before pressing send is what actually gets sent, whatever happens to the
     * customer table while the batches run.
     */
    public function create(string $message, ?string $title = null): SmsCampaign
    {
        return DB::transaction(function () use ($message, $title) {
            $campaign = SmsCampaign::create([
                'title'   => $title,
                'message' => $message,
                'status'  => SmsCampaignStatus::DRAFT,
            ]);

            $seen = [];
            $total = 0;

            $this->recipientQuery()->chunk(500, function ($users) use ($campaign, &$seen, &$total) {
                $rows = [];

                foreach ($users as $user) {
                    $key = $this->normalisePhone($user->country_code, $user->phone);

                    if (isset($seen[$key])) {
                        continue;
                    }

                    $seen[$key] = true;

                    $rows[] = [
                        'sms_campaign_id' => $campaign->id,
                        'user_id'         => $user->id,
                        'name'            => $user->name,
                        'country_code'    => $user->country_code,
                        'phone'           => $user->phone,
                        'status'          => SmsRecipientStatus::PENDING,
                        'created_at'      => now(),
                        'updated_at'      => now(),
                    ];
                }

                if ($rows) {
                    SmsCampaignRecipient::insert($rows);
                    $total += count($rows);
                }
            });

            $campaign->update(['total_count' => $total]);

            return $campaign->fresh();
        });
    }

    /**
     * Sends the next batch.
     *
     * A gateway failure marks that one recipient FAILED and the run carries on;
     * one bad number must not strand the rest of the list.
     */
    public function sendBatch(SmsCampaign $campaign, int $size = self::BATCH_SIZE): SmsCampaign
    {
        $sms = app(SmsManagerService::class)->gateway(app(SmsService::class)->gateway());

        if (!$sms->status()) {
            throw new \Exception('SMS gateway is not enabled. Turn one on in Settings > SMS Gateway.');
        }

        $campaign->update(['status' => SmsCampaignStatus::SENDING]);

        $recipients = $campaign->recipients()->pending()->orderBy('id')->limit($size)->get();

        foreach ($recipients as $recipient) {
            try {
                $sms->send(
                    $recipient->country_code,
                    $recipient->phone,
                    $this->render($campaign->message, $recipient->name)
                );

                $recipient->update(['status' => SmsRecipientStatus::SENT, 'error' => null]);
                $campaign->increment('sent_count');
            } catch (Throwable $exception) {
                Log::info('SMS campaign send failed: ' . $exception->getMessage());

                $recipient->update([
                    'status' => SmsRecipientStatus::FAILED,
                    'error'  => mb_substr($exception->getMessage(), 0, 190),
                ]);
                $campaign->increment('failed_count');
            }
        }

        $campaign->refresh();

        if (!$campaign->recipients()->pending()->exists()) {
            $campaign->update(['status' => SmsCampaignStatus::COMPLETED]);
        }

        return $campaign->fresh();
    }

    public function pause(SmsCampaign $campaign): SmsCampaign
    {
        if ($campaign->status !== SmsCampaignStatus::COMPLETED) {
            $campaign->update(['status' => SmsCampaignStatus::PAUSED]);
        }

        return $campaign->fresh();
    }

    /** One message to one number, so the template can be proofread for real. */
    public function sendTest(string $countryCode, string $phone, string $message, string $name): void
    {
        $sms = app(SmsManagerService::class)->gateway(app(SmsService::class)->gateway());

        if (!$sms->status()) {
            throw new \Exception('SMS gateway is not enabled. Turn one on in Settings > SMS Gateway.');
        }

        $sms->send($countryCode, $phone, $this->render($message, $name));
    }

    /**
     * Fills the template in.
     *
     * A customer with no name would otherwise be greeted as "Dear ," - they get
     * the generic word instead.
     */
    public function render(string $message, ?string $name): string
    {
        return strtr($message, [
            self::PLACEHOLDER => trim((string) $name) !== '' ? $name : trans('all.label.customer'),
        ]);
    }

    /**
     * Country code and number as one digit string.
     *
     * A leading zero is dropped when a country code is present: Bangladeshi
     * numbers are stored both as "01711..." and "1711...", and without this the
     * same person counts as two recipients and pays for two messages.
     */
    private function normalisePhone(?string $countryCode, string $phone): string
    {
        $code   = preg_replace('/[^0-9]/', '', (string) $countryCode);
        $number = preg_replace('/[^0-9]/', '', $phone);

        if ($code !== '') {
            $number = ltrim($number, '0');
        }

        return $code . $number;
    }
}
