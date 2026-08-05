<?php

use App\Enums\Status;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Concerns / tags — acne, sunburn, tan, dark spots, hair fall.
 *
 * A second, independent taxonomy alongside blog_categories. A post about
 * sunscreen belongs to the "Sunscreen" CATEGORY (mirroring the shop's product
 * categories) while also being tagged with the CONCERNS it addresses —
 * "sunburn", "tan". Readers arrive searching the concern ("tan removal cream
 * bangladesh"), not the product category, so each concern needs its own
 * indexable landing page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_tags', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('description', 500)->nullable();

            $table->string('meta_title')->nullable();
            $table->string('meta_description', 500)->nullable();
            $table->text('meta_keywords')->nullable();

            $table->integer('priority')->default(0);
            $table->tinyInteger('status')->default(Status::ACTIVE);
            $table->timestamps();

            $table->index(['status', 'priority']);
        });

        Schema::create('blog_post_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_post_id')->constrained('blog_posts')->cascadeOnDelete();
            $table->foreignId('blog_tag_id')->constrained('blog_tags')->cascadeOnDelete();

            // Stops a double-submit from attaching the same concern twice and
            // inflating the count shown on the concern chips.
            $table->unique(['blog_post_id', 'blog_tag_id'], 'blog_post_tag_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_post_tag');
        Schema::dropIfExists('blog_tags');
    }
};
