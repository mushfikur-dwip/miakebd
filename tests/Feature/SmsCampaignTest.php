<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\Role as EnumRole;
use App\Enums\SmsCampaignStatus;
use App\Enums\SmsRecipientStatus;
use App\Models\Menu;
use App\Models\SmsCampaign;
use App\Models\User;
use App\Services\SmsCampaignService;
use App\Services\SmsManagerService;
use App\Services\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A promotional blast costs real money per recipient and cannot be recalled, so
 * the things that must not go wrong are: nobody is texted twice, the template
 * is filled in per person, and a stopped run resumes without re-sending.
 */
class SmsCampaignTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Admin', 'Customer', 'Manager', 'POS Operator', 'Stuff'] as $name) {
            Role::findOrCreate($name, 'sanctum');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function customer(string $name, string $phone, string $code = '+880', int $guest = Ask::NO): User
    {
        $slug = strtolower(str_replace(' ', '', $name)) . rand(1, 999999);

        $user = User::create([
            'name'         => $name,
            'username'     => $slug,
            'email'        => $slug . '@example.com',
            'password'     => bcrypt('secret123'),
            'phone'        => $phone,
            'country_code' => $code,
            'is_guest'     => $guest,
            'status'       => \App\Enums\Status::ACTIVE,
        ]);
        $user->assignRole(EnumRole::CUSTOMER);

        return $user;
    }

    /** A gateway that records what it was asked to send instead of sending it. */
    private function fakeGateway(array &$sent, bool $enabled = true): void
    {
        $this->mock(SmsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('gateway')->andReturn('twilio');
        });

        $this->mock(SmsManagerService::class, function (MockInterface $mock) use (&$sent, $enabled) {
            $mock->shouldReceive('gateway')->andReturnSelf();
            $mock->shouldReceive('status')->andReturn($enabled);
            $mock->shouldReceive('send')->andReturnUsing(function ($code, $phone, $message) use (&$sent) {
                $sent[] = ['phone' => $phone, 'message' => $message];
                return true;
            });
        });
    }

    public function test_the_same_person_is_only_listed_once(): void
    {
        // Three guest-checkout rows for one phone, plus the real account. This
        // is exactly what a repeat guest buyer looks like in the table.
        $this->customer('Rima', '01711111111');
        $this->customer('Rima Guest', '01711111111', '+880', Ask::YES);
        $this->customer('Rima Guest 2', '01711111111', '+880', Ask::YES);
        $this->customer('Karim', '01822222222');

        $campaign = app(SmsCampaignService::class)->create('Hi {name}');

        $this->assertSame(2, $campaign->total_count);
    }

    public function test_a_leading_zero_does_not_split_one_person_in_two(): void
    {
        $this->customer('Rima', '01711111111');
        $this->customer('Rima again', '1711111111');

        $this->assertSame(1, app(SmsCampaignService::class)->create('Hi {name}')->total_count);
    }

    public function test_customers_without_a_phone_are_left_out(): void
    {
        $this->customer('Has phone', '01711111111');

        $noPhone = User::create([
            'name'     => 'No phone',
            'username' => 'nophone',
            'email'    => 'nophone@example.com',
            'password' => bcrypt('secret123'),
            'status'   => \App\Enums\Status::ACTIVE,
        ]);
        $noPhone->assignRole(EnumRole::CUSTOMER);

        $this->assertSame(1, app(SmsCampaignService::class)->create('Hi {name}')->total_count);
    }

    public function test_each_recipient_gets_their_own_name(): void
    {
        $this->customer('Rima', '01711111111');
        $this->customer('Karim', '01822222222');

        $sent = [];
        $this->fakeGateway($sent);

        $service  = app(SmsCampaignService::class);
        $campaign = $service->create('Hi {name}, 20% off!');
        $service->sendBatch($campaign);

        $messages = array_column($sent, 'message');
        sort($messages);

        $this->assertSame(['Hi Karim, 20% off!', 'Hi Rima, 20% off!'], $messages);
    }

    public function test_a_customer_with_no_name_is_not_greeted_as_blank(): void
    {
        $this->assertSame('Dear Customer', app(SmsCampaignService::class)->render('Dear {name}', ''));
        $this->assertSame('Dear Customer', app(SmsCampaignService::class)->render('Dear {name}', null));
    }

    public function test_sending_completes_across_several_batches(): void
    {
        foreach (range(1, 5) as $i) {
            $this->customer('Customer ' . $i, '0171111111' . $i);
        }

        $sent = [];
        $this->fakeGateway($sent);

        $service  = app(SmsCampaignService::class);
        $campaign = $service->create('Hi {name}');

        $campaign = $service->sendBatch($campaign, 2);
        $this->assertSame(2, $campaign->sent_count);
        $this->assertSame(SmsCampaignStatus::SENDING, $campaign->status);

        $campaign = $service->sendBatch($campaign, 2);
        $campaign = $service->sendBatch($campaign, 2);

        $this->assertSame(5, $campaign->sent_count);
        $this->assertSame(SmsCampaignStatus::COMPLETED, $campaign->status);
        $this->assertCount(5, $sent);
    }

    public function test_resuming_does_not_text_anyone_twice(): void
    {
        foreach (range(1, 4) as $i) {
            $this->customer('Customer ' . $i, '0171111111' . $i);
        }

        $sent = [];
        $this->fakeGateway($sent);

        $service  = app(SmsCampaignService::class);
        $campaign = $service->create('Hi {name}');

        $service->sendBatch($campaign, 2);
        $campaign = $service->pause($campaign->fresh());
        $this->assertSame(SmsCampaignStatus::PAUSED, $campaign->status);

        // Resume, then run to the end.
        $service->sendBatch($campaign, 2);

        $phones = array_column($sent, 'phone');
        $this->assertCount(4, $phones);
        $this->assertSame(4, count(array_unique($phones)), 'a recipient was texted twice');
    }

    public function test_a_failing_number_does_not_strand_the_rest(): void
    {
        $this->customer('Good one', '01711111111');
        $this->customer('Bad one', '01822222222');
        $this->customer('Good two', '01933333333');

        $this->mock(SmsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('gateway')->andReturn('twilio');
        });
        $this->mock(SmsManagerService::class, function (MockInterface $mock) {
            $mock->shouldReceive('gateway')->andReturnSelf();
            $mock->shouldReceive('status')->andReturn(true);
            $mock->shouldReceive('send')->andReturnUsing(function ($code, $phone) {
                if ($phone === '01822222222') {
                    throw new \Exception('gateway rejected the number');
                }
                return true;
            });
        });

        $service  = app(SmsCampaignService::class);
        $campaign = $service->sendBatch($service->create('Hi {name}'));

        $this->assertSame(2, $campaign->sent_count);
        $this->assertSame(1, $campaign->failed_count);
        $this->assertSame(SmsCampaignStatus::COMPLETED, $campaign->status);
    }

    public function test_nothing_is_sent_when_no_gateway_is_enabled(): void
    {
        $this->customer('Rima', '01711111111');

        $sent = [];
        $this->fakeGateway($sent, false);

        $service  = app(SmsCampaignService::class);
        $campaign = $service->create('Hi {name}');

        $this->expectException(\Exception::class);

        try {
            $service->sendBatch($campaign);
        } finally {
            $this->assertCount(0, $sent);
            $this->assertSame(
                0,
                SmsCampaign::find($campaign->id)->recipients()
                    ->where('status', '!=', SmsRecipientStatus::PENDING)->count()
            );
        }
    }

    /**
     * Rebuilds the menus table in the order a live site actually has it.
     *
     * RefreshDatabase runs migrations before seeders, so the mobile-section row
     * (added by a 2026 migration, and the only row not on priority 100) ends up
     * with the lowest id here - the reverse of production, where it was added
     * long after the seeded rows. Comparing orderings against that would be
     * testing the fixture, not the code.
     */
    private function seedMenusLikeProduction(): int
    {
        Menu::query()->delete();

        $this->seed(\Database\Seeders\MenuTableSeeder::class);

        // DB::table, not Menu::create: the model's $fillable lists `parent_id`,
        // a column that does not exist, so mass assignment silently drops the
        // real `parent` and every row lands at top level. The migration inserts
        // the same way, so this exercises the production path.
        DB::table('menus')->insert([
            'name' => 'Mobile Section', 'language' => 'mobile_section', 'url' => 'mobile-section',
            'icon' => 'lab lab-line-mobile', 'parent' => 0, 'type' => 1, 'priority' => 25,
            'status' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) Menu::where('url', 'customers')->value('parent');
    }

    public function test_the_menu_entry_sits_directly_above_customers(): void
    {
        $parent = $this->seedMenusLikeProduction();

        DB::table('menus')->insert([
            'name' => 'Customer Message', 'language' => 'customer_message', 'url' => 'customer-message',
            'icon' => 'lab lab-line-mail', 'parent' => $parent, 'type' => 1, 'priority' => 101,
            'status' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $section = Menu::orderBy('priority', 'desc')->orderBy('id')->get()
            ->where('parent', $parent)->pluck('url')->values()->all();

        $this->assertSame('customer-message', $section[0]);
        $this->assertContains('customers', $section);
    }

    public function test_ordering_menus_does_not_reshuffle_the_existing_ones(): void
    {
        $this->seedMenusLikeProduction();

        $byId       = Menu::orderBy('id')->pluck('url')->all();
        $byPriority = Menu::orderBy('priority', 'desc')->orderBy('id')->pluck('url')->all();

        // Ordering was implicit before; it must reproduce the same sidebar, or
        // every admin's menu silently rearranges on deploy.
        $this->assertSame($byId, $byPriority);
    }
}
