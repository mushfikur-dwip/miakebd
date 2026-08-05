<?php

use App\Enums\Status;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Unique because the slug is the public URL segment
            // (/blog/category/{slug}). Two categories sharing one would make a
            // second, unreachable page that still ends up in the sitemap.
            $table->string('slug')->unique();
            $table->string('description', 500)->nullable();

            // SEO overrides. All nullable — BlogMetaResolver composes a sane
            // default from the name and live post count when they are blank,
            // so an admin never has to fill these in to rank.
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 500)->nullable();
            $table->text('meta_keywords')->nullable();

            // Drives the order of the public category nav bar.
            $table->integer('priority')->default(0);
            $table->tinyInteger('status')->default(Status::ACTIVE);
            $table->timestamps();

            $table->index(['status', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_categories');
    }
};
