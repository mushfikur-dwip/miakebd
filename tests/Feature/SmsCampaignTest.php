<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Http\Controllers\Admin\SmsCampaignController;
use App\Enums\MenuType;
use App\Services\MenuService;
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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route as RouteFacade;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
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
     * A small menus table with explicit ids.
     *
     * The real seeder is not usable here: it stores each child's `parent` as
     * the row's position in its own array and relies on auto-increment ids
     * landing on the same numbers, which stops being true the moment anything
     * else has already written to the table - as a migration does under
     * RefreshDatabase. Writing the ids out keeps the fixture honest.
     */
    private function seedMenus(): void
    {
        Menu::query()->delete();

        $rows = [
            ['id' => 1, 'name' => 'Users', 'language' => 'users', 'url' => '#', 'parent' => 0, 'priority' => 100],
            ['id' => 2, 'name' => 'Administrators', 'language' => 'administrators', 'url' => 'administrators', 'parent' => 1, 'priority' => 100],
            ['id' => 3, 'name' => 'Customers', 'language' => 'customers', 'url' => 'customers', 'parent' => 1, 'priority' => 100],
            ['id' => 4, 'name' => 'Employees', 'language' => 'employees', 'url' => 'employees', 'parent' => 1, 'priority' => 100],
            ['id' => 5, 'name' => 'Setup', 'language' => 'setup', 'url' => '#', 'parent' => 0, 'priority' => 100],
            ['id' => 6, 'name' => 'Settings', 'language' => 'settings', 'url' => 'settings', 'parent' => 5, 'priority' => 100],
            // Added by a later migration, and the only row not on priority 100.
            ['id' => 7, 'name' => 'Mobile Section', 'language' => 'mobile_section', 'url' => 'mobile-section', 'parent' => 0, 'priority' => 25],
        ];

        foreach ($rows as $row) {
            DB::table('menus')->insert($row + [
                'icon' => 'lab lab-line-mail', 'type' => MenuType::BACKEND, 'status' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function addCustomerMessageMenu(): void
    {
        DB::table('menus')->insert([
            'id' => 20, 'name' => 'Customer Message', 'language' => 'customer_message',
            'url' => 'customer-message', 'parent' => 1, 'type' => MenuType::BACKEND,
            'icon' => 'lab lab-line-mail', 'priority' => 101, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** The sidebar exactly as an admin receives it after logging in. */
    private function sidebar(): array
    {
        return app(MenuService::class)->menu(Role::findByName('Admin', 'sanctum'));
    }

    public function test_the_menu_entry_reaches_the_sidebar_above_customers(): void
    {
        $this->seedMenus();
        $this->addCustomerMessageMenu();

        $section = collect($this->sidebar())
            ->first(fn($item) => collect($item['children'] ?? [])->contains('url', 'customers'));

        $this->assertNotNull($section, 'the section holding Customers vanished from the sidebar');

        $urls = collect($section['children'])->pluck('url')->all();

        // The bug this locks down: ordering the flat list by priority put this
        // row ahead of its own parent, and the single-pass tree builder threw
        // it away - the menu never appeared at all.
        $this->assertSame('customer-message', $urls[0]);
        $this->assertSame(['customer-message', 'administrators', 'customers', 'employees'], $urls);
    }

    public function test_the_other_sections_keep_their_order(): void
    {
        $this->seedMenus();
        $this->addCustomerMessageMenu();

        $sidebar = collect($this->sidebar());

        // Mobile Section carries priority 25 and must still come last, where
        // its id puts it - sections are not resorted by priority.
        $this->assertSame(['#', '#', 'mobile-section'], $sidebar->pluck('url')->all());
        $this->assertSame(['settings'], collect($sidebar[1]['children'])->pluck('url')->all());
    }

    public function test_mobile_section_buttons_never_reach_the_admin_sidebar(): void
    {
        $this->seedMenus();

        // What the Mobile Section page creates: no language, so it rendered in
        // the sidebar as a stray "Menu.Null".
        DB::table('menus')->insert([
            'id' => 30, 'name' => 'Shop Now', 'url' => 'shop-now', 'icon' => '', 'parent' => 0,
            'type' => MenuType::MOBILE_SECTION, 'priority' => 100, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertNotContains('shop-now', collect($this->sidebar())->pluck('url')->all());
    }

    /**
     * The page calls /api/admin/customer-message. Nothing else checked that the
     * routes were actually published there - they were first written inside the
     * `setting` group, which silently prefixed them to
     * /api/admin/setting/customer-message, and every call 404'd.
     */
    public function test_the_routes_answer_on_the_paths_the_page_calls(): void
    {
        $paths = [
            ['GET', 'api/admin/customer-message'],
            ['GET', 'api/admin/customer-message/audience'],
            ['GET', 'api/admin/customer-message/show/1'],
            ['POST', 'api/admin/customer-message'],
            ['POST', 'api/admin/customer-message/test'],
            ['POST', 'api/admin/customer-message/1/batch'],
            ['POST', 'api/admin/customer-message/1/pause'],
        ];

        foreach ($paths as [$method, $uri]) {
            try {
                RouteFacade::getRoutes()->match(Request::create($uri, $method));
            } catch (\Throwable $exception) {
                $this->fail("{$method} /{$uri} is not routable: " . $exception->getMessage());
            }
        }

        $this->assertTrue(true);
    }

    /**
     * Not just "something answers here" - the right controller answers.
     *
     * A prefix group swallowing these routes was the original fault, and a
     * wildcard route elsewhere can match a path without being the route meant,
     * so the action is asserted rather than the absence of a 404.
     */
    public function test_the_paths_resolve_to_the_campaign_controller(): void
    {
        $expected = [
            ['GET', 'api/admin/customer-message', 'index'],
            ['GET', 'api/admin/customer-message/audience', 'audience'],
            ['POST', 'api/admin/customer-message', 'store'],
            ['POST', 'api/admin/customer-message/test', 'test'],
            ['POST', 'api/admin/customer-message/1/batch', 'batch'],
            ['POST', 'api/admin/customer-message/1/pause', 'pause'],
        ];

        foreach ($expected as [$method, $uri, $action]) {
            $route = RouteFacade::getRoutes()->match(Request::create($uri, $method));

            $this->assertSame(
                SmsCampaignController::class . '@' . $action,
                $route->getActionName(),
                "{$method} /{$uri} went somewhere else"
            );
        }
    }
}
