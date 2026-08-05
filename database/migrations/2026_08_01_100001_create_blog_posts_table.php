<?php

use App\Enums\Status;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_posts', function (Blueprint $table) {
            $table->id();

            // nullOnDelete rather than cascade: deleting a category must not
            // silently delete the posts filed under it. They fall back to
            // "Uncategorized" on the public side and stay editable.
            $table->foreignId('blog_category_id')
                ->nullable()
                ->constrained('blog_categories')
                ->nullOnDelete();

            $table->string('title');
            $table->string('slug')->unique();
            $table->string('excerpt', 500)->nullable();
            $table->longText('content');

            $table->string('author_name')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();

            $table->boolean('is_featured')->default(false);
            $table->unsignedBigInteger('views')->default(0);

            // Separate from created_at so a post can be written today and dated
            // for launch day. Null means "not yet published" regardless of
            // status, which is what keeps drafts out of the sitemap.
            $table->timestamp('published_at')->nullable();

            // ---- SEO ----
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 500)->nullable();
            $table->text('meta_keywords')->nullable();
            // Set only when this post deliberately points at another URL, e.g.
            // a piece syndicated from elsewhere. Blank self-canonicalises.
            $table->string('canonical_url')->nullable();
            // "index, follow" unless an admin wants a thin post kept out of
            // Google while still linked internally.
            $table->string('robots')->nullable();
            $table->tinyInteger('status')->default(Status::ACTIVE);

            $table->timestamps();

            // The public list query is status + published_at ordered by
            // published_at, and the sitemap runs the same filter.
            $table->index(['status', 'published_at']);
            $table->index(['blog_category_id', 'status', 'published_at'], 'blog_posts_category_feed_index');
            $table->index('is_featured');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_posts');
    }
};
