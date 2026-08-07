<?php

use App\Enums\CampaignType;
use App\Enums\Status;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('slug')->unique();
            $table->unsignedTinyInteger('type')->default(CampaignType::CLEARANCE);

            // Both required. The countdown on the public page is driven by
            // ends_at, and "active" is a window, not just a flag — a campaign
            // dated for next week must not price anything today.
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');

            // The admin's on/off switch, independent of the window. An expired
            // campaign can stay ACTIVE in the list without pricing anything.
            $table->unsignedTinyInteger('status')->default(Status::ACTIVE);

            $table->timestamps();

            // Every public query is status + the active window, so the three
            // columns are indexed together.
            $table->index(['status', 'starts_at', 'ends_at'], 'campaigns_active_window_index');
        });

        Schema::create('campaign_products', function (Blueprint $table) {
            $table->id();

            // cascade on both sides: a campaign_products row is meaningless
            // without its campaign, and a deleted product must not leave a
            // priced row behind that the campaign page would try to render.
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();

            // Authoritative for this campaign's page and nothing else. It is
            // never derived from products.selling_price and never written back
            // to it — see the page-scoped pricing decision in the plan.
            // Same precision as products.selling_price so a price entered here
            // survives the round trip unchanged.
            $table->decimal('special_price', 19, 6)->unsigned();

            $table->timestamps();

            // One price per product per campaign. Without this the picker
            // could add the same product twice and the page would show it at
            // two prices.
            $table->unique(['campaign_id', 'product_id'], 'campaign_products_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_products');
        Schema::dropIfExists('campaigns');
    }
};
