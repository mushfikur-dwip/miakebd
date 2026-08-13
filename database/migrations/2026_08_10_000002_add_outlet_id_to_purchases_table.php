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
        // Guarded so a re-run after a failure further down the batch finishes
        // the job instead of dying on "duplicate column".
        if (Schema::hasColumn('purchases', 'outlet_id')) {
            return;
        }

        Schema::table('purchases', function (Blueprint $table) {
            $table->foreignId('outlet_id')->nullable()->after('supplier_id')->constrained('outlets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('purchases', 'outlet_id')) {
            return;
        }

        Schema::table('purchases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('outlet_id');
        });
    }
};
