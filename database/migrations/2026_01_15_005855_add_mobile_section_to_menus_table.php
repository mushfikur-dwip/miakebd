<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        \Illuminate\Support\Facades\DB::table('menus')->insert([
            'name'       => 'Mobile Section',
            'url'        => 'mobile-section',
            'icon'       => 'fa-solid fa-mobile-screen',
            'status'     => 1,
            'priority'   => 25,
            'type'       => 1, // BACKEND
            'language'   => 'mobile_section',
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        \Illuminate\Support\Facades\DB::table('menus')->where('url', 'mobile-section')->where('type', 1)->delete();
    }
};
