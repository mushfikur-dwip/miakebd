<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Null for every existing video: its shape is then worked out from the
        // link (VideoEmbed::isPortrait), so nothing has to be re-entered.
        Schema::table('product_videos', function (Blueprint $table) {
            $table->unsignedTinyInteger('orientation')->nullable()->after('link');
        });
    }

    public function down(): void
    {
        Schema::table('product_videos', function (Blueprint $table) {
            $table->dropColumn('orientation');
        });
    }
};
