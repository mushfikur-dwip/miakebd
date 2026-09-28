<?php

namespace Tests\Feature;

use App\Enums\SwitchBox;
use App\Models\NotificationAlert;
use App\Services\NotificationAlertService;
use Database\Seeders\NotificationAlertTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Admin -> Settings -> Notification Alert. The form posts one field per
 * message (named by the alert's id) and one per switch (type + id), exactly as
 * NotificationAlertComponent builds it.
 */
class NotificationAlertSaveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(NotificationAlertTableSeeder::class);
    }

    /** The form as the browser sends it; the mail and push tabs leave the two POS rows off. */
    private function form(string $type, callable $message): array
    {
        $alerts = NotificationAlert::all();
        if ($type !== 'sms') {
            $alerts = $alerts->reject(fn ($a) => in_array($a->language, ['pos_order_message', 'pos_customer_message'], true));
        }

        $form = ['type' => $type];
        foreach ($alerts as $alert) {
            $form[(string) $alert->id] = $message($alert);
            $form[$type . $alert->id] = (string) SwitchBox::ON;
        }

        return $form;
    }

    public function test_each_tab_saves_its_switches_and_messages(): void
    {
        foreach (['mail', 'sms', 'push_notification'] as $type) {
            $request = Request::create('/', 'PUT', $this->form($type, fn ($a) => "{$type} for {$a->name}"));

            app(NotificationAlertService::class)->update($request);
        }

        $sample = NotificationAlert::where('language', 'pos_order_message')->first();
        $this->assertSame('sms for ' . $sample->name, $sample->sms_message);
        $this->assertSame(SwitchBox::ON, (int) $sample->sms);

        $first = NotificationAlert::whereNotIn('language', ['pos_order_message', 'pos_customer_message'])->orderBy('id')->first();
        $this->assertSame('mail for ' . $first->name, $first->mail_message);
        $this->assertSame(SwitchBox::ON, (int) $first->push_notification);
    }

    /**
     * The messages were VARCHAR(255): a longer SMS template made MySQL refuse
     * the whole tab. SQLite does not enforce lengths, so the column type is
     * what is checked here.
     */
    public function test_a_long_message_fits(): void
    {
        foreach (['mail_message', 'sms_message', 'push_notification_message'] as $column) {
            $this->assertSame('text', \Illuminate\Support\Facades\Schema::getColumnType('notification_alerts', $column), $column);
        }

        $long = str_repeat('প্রিয় {name}, আপনার অর্ডার #{order} গ্রহণ করা হয়েছে। ', 10);
        app(NotificationAlertService::class)->update(Request::create('/', 'PUT', $this->form('sms', fn () => $long)));

        $this->assertSame($long, NotificationAlert::first()->sms_message);
    }
}
