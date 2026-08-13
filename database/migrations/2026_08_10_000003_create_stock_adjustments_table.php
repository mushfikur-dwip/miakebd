<?php

use App\Enums\StockAdjustmentType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Manual branch stock corrections. The line items live in `stocks` with
     * model_type = StockAdjustment, exactly like a purchase or a damage, so
     * every existing quantity sum picks them up for free.
     *
     * TRANSFER writes two rows per item - a negative one at from_outlet_id
     * and a positive one at to_outlet_id - so the grand total the website
     * shows does not move. ADD and REMOVE write a single row and do change
     * the total, which is what you want for a stock count correction.
     *
     * A NULL outlet on either side means the unassigned pool, which is where
     * all stock recorded before branch-wise tracking sits.
     */
    public function up(): void
    {
        if (Schema::hasTable('stock_adjustments')) {
            return;
        }

        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('type')->default(StockAdjustmentType::TRANSFER);
            $table->foreignId('from_outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
            $table->foreignId('to_outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
            $table->timestamp('date');
            $table->string('reference_no')->nullable();
            $table->text('note')->nullable();
            $table->string('creator_type')->nullable();
            $table->bigInteger('creator_id')->nullable();
            $table->string('editor_type')->nullable();
            $table->bigInteger('editor_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustments');
    }
};
