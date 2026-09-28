<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The employee who counted the notes, picked on the count form. Not
        // always whoever typed it in (created_by); null on older counts,
        // which then show their creator.
        Schema::table('cash_counts', function (Blueprint $table) {
            $table->unsignedBigInteger('counted_by_id')->nullable()->after('entry_id');
        });
    }

    public function down(): void
    {
        Schema::table('cash_counts', function (Blueprint $table) {
            $table->dropColumn('counted_by_id');
        });
    }
};
