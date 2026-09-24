<?php

use App\Enums\SliderPosition;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds the home-page position to sliders.
     *
     * Guarded with hasColumn because this ships in a full-codebase zip that may
     * be unzipped over a server where it has already run.
     */
    public function up(): void
    {
        if (Schema::hasColumn('sliders', 'position')) {
            return;
        }

        Schema::table('sliders', function (Blueprint $table) {
            // Defaults to HERO so existing rows stay in the carousel. Without
            // the default they would land on 0, match no position filter, and
            // the live hero slider would silently go empty.
            $table->unsignedTinyInteger('position')
                ->default(SliderPosition::HERO)
                ->after('link');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('sliders', 'position')) {
            return;
        }

        Schema::table('sliders', function (Blueprint $table) {
            $table->dropColumn('position');
        });
    }
};
