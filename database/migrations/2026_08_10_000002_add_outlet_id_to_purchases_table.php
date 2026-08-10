<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which branch the purchased goods were received into. NULL keeps the
     * old behaviour - the stock lands in the unassigned pool and can be
     * moved to a branch later from the stock adjustment screen.
     */
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->foreignId('outlet_id')->nullable()->after('supplier_id')->constrained('outlets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('outlet_id');
        });
    }
};
