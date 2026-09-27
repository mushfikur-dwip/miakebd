<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A banner's words are usually in the picture itself, so its title is
     * optional; the storefront draws no caption when there is none.
     */
    public function up(): void
    {
        Schema::table('sliders', function (Blueprint $table) {
            $table->text('title')->nullable()->change();
        });
    }

    /** Untitled rows get an empty title, which NOT NULL accepts. */
    public function down(): void
    {
        DB::table('sliders')->whereNull('title')->update(['title' => '']);

        Schema::table('sliders', function (Blueprint $table) {
            $table->text('title')->nullable(false)->change();
        });
    }
};
