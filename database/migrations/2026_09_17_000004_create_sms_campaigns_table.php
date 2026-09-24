<?php

use App\Enums\MenuType;
use App\Enums\SmsCampaignStatus;
use App\Enums\SmsRecipientStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bulk promotional SMS.
 *
 * The recipient list is materialised into its own table when the campaign is
 * created rather than paged straight off users. Three reasons:
 *
 * - guest-checkout rows share phone numbers (see User::scopeNotGuest), so the
 *   list has to be deduplicated by phone or one person gets the same promo
 *   four times;
 * - the queue runs on `sync`, so sending happens in batches across separate
 *   requests - a cursor over a live users table would skip or repeat rows as
 *   people sign up mid-send;
 * - progress and cost are then exact numbers rather than estimates, which
 *   matters when every row costs money.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('sms_campaigns')) {
            Schema::create('sms_campaigns', function (Blueprint $table) {
                $table->id();
                $table->string('title')->nullable();
                $table->text('message');
                $table->unsignedTinyInteger('status')->default(SmsCampaignStatus::DRAFT);
                $table->unsignedInteger('total_count')->default(0);
                $table->unsignedInteger('sent_count')->default(0);
                $table->unsignedInteger('failed_count')->default(0);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('sms_campaign_recipients')) {
            Schema::create('sms_campaign_recipients', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sms_campaign_id')->constrained('sms_campaigns')->cascadeOnDelete();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('name')->nullable();
                $table->string('country_code')->nullable();
                $table->string('phone');
                $table->unsignedTinyInteger('status')->default(SmsRecipientStatus::PENDING);
                $table->string('error')->nullable();
                $table->timestamps();

                // Every batch asks the same question: the next N pending rows
                // for this campaign.
                $table->index(['sms_campaign_id', 'status']);
            });
        }

        // Sits above Customers, inside the same section. The column is `parent`
        // and it holds the parent menu's id (see AppLibrary).
        $parent = DB::table('menus')->where('url', 'customers')->value('parent');

        if ($parent && !DB::table('menus')->where('url', 'customer-message')->exists()) {
            DB::table('menus')->insert([
                'name'     => 'Customer Message',
                'language' => 'customer_message',
                'url'      => 'customer-message',
                'icon'     => 'lab lab-line-mail',
                'parent'   => $parent,
                'type'     => MenuType::BACKEND,
                // Higher than the flat 100 every seeded row carries; that is
                // what floats it above Customers once menus are ordered.
                'priority' => 101,
                'status'   => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('menus')->where('url', 'customer-message')->delete();
        Schema::dropIfExists('sms_campaign_recipients');
        Schema::dropIfExists('sms_campaigns');
    }
};
