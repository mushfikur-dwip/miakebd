<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Http\Resources\BlogCardResource;
use App\Http\Resources\BlogCategoryNavResource;
use App\Http\Resources\BlogDetailsResource;
use App\Http\Resources\BlogTagResource;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\BlogTag;
use Exception;
use Illuminate\Http\Request;

class BlogController extends Controller
{
    /** Hard ceiling on per_page so a crafted request cannot ask for every row. */
    private const MAX_PER_PAGE = 24;

    /**
     * Paginated feed. Optional ?category={slug} and ?search={term}.
     */
    public function index(Request $request)
    {
        try {
            $perPage = max(1, min((int) $request->get('per_page', 9) ?: 9, self::MAX_PER_PAGE));

            $posts = BlogPost::with(['media', 'category'])
                ->published()
                ->when($request->filled('category'), function ($query) use ($request) {
                    $query->whereHas('category', fn($q) => $q->where('slug', $request->get('category')));
                })
                // Concern filter — /blog/tag/acne. Independent of category, so
                // the two can be combined.
                ->when($request->filled('tag'), function ($query) use ($request) {
                    $query->whereHas('tags', fn($q) => $q->where('blog_tags.slug', $request->get('tag')));
                })
                ->when($request->filled('search'), function ($query) use ($request) {
                    $term = '%' . $request->get('search') . '%';
                    $query->where(fn($q) => $q->where('title', 'like', $term)
                        ->orWhere('excerpt', 'like', $term));
                })
                ->orderByDesc('published_at')
                ->paginate($perPage);

            return BlogCardResource::collection($posts);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    /**
     * One post by slug. 404s on a draft rather than rendering it, so an
     * unpublished URL cannot be shared or indexed.
     */
    public function show(string $slug)
    {
        try {
            $post = BlogPost::with(['media', 'category', 'tags'])
                ->published()
                ->where('slug', $slug)
                ->first();

            if (!$post) {
                return response(['status' => false, 'message' => 'Post not found.'], 404);
            }

            // increment() writes directly and skips the model's updated_at, so
            // a view does not bump the sitemap's lastmod for every visitor.
            //
            // Skipped for crawlers. Googlebot, WhatsApp's preview fetcher and
            // the AI crawlers all hit this endpoint, and counting them made
            // "Most read" rank by crawl frequency rather than by readers.
            if (!$this->isCrawler(request()->userAgent())) {
                $post->increment('views');
            }

            return new BlogDetailsResource($post);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    /**
     * Newest posts in the same category, excluding the current one. Falls back
     * to site-wide recent posts when the category is thin, so the "read next"
     * strip is never empty — an empty related block is a dead end for both
     * readers and internal linking.
     */
    public function related(string $slug)
    {
        try {
            $post = BlogPost::published()->where('slug', $slug)->first();

            if (!$post) {
                return BlogCardResource::collection(collect());
            }

            $query = BlogPost::with(['media', 'category'])
                ->published()
                ->where('id', '!=', $post->id);

            $related = (clone $query)
                ->where('blog_category_id', $post->blog_category_id)
                ->orderByDesc('published_at')
                ->limit(3)
                ->get();

            if ($related->count() < 3) {
                $filler = $query->whereNotIn('id', $related->pluck('id')->push($post->id))
                    ->orderByDesc('published_at')
                    ->limit(3 - $related->count())
                    ->get();

                $related = $related->concat($filler);
            }

            return BlogCardResource::collection($related);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    /**
     * Categories for the nav bar and sidebar, with published post counts.
     */
    public function categories()
    {
        try {
            $categories = BlogCategory::query()
                ->with('media')
                ->active()
                ->withCount(['posts as published_posts_count' => fn($query) => $query->published()])
                ->orderBy('priority')
                ->orderBy('name')
                ->get();

            return BlogCategoryNavResource::collection($categories);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    /**
     * Cheap user-agent check. Not security — just keeps the view counter, and
     * therefore the "Most read" list, reflecting humans.
     */
    private function isCrawler(?string $userAgent): bool
    {
        if (blank($userAgent)) {
            return true;
        }

        return (bool) preg_match(
            '/bot|crawl|spider|slurp|facebookexternalhit|whatsapp|telegram|preview|headless|lighthouse|gptbot|claudebot|perplexity/i',
            $userAgent
        );
    }

    /**
     * Concerns for the "Shop by concern" chips and the tag landing pages.
     * Only concerns that actually have a published post — an empty chip is a
     * dead end for the reader and a thin page for Google.
     */
    public function tags()
    {
        try {
            $tags = BlogTag::query()
                ->active()
                ->withCount(['posts as published_posts_count' => fn($query) => $query->published()])
                // See sections(): whereHas rather than having() on the alias.
                ->whereHas('posts', fn($query) => $query->published())
                ->orderBy('priority')
                ->orderBy('name')
                ->get();

            return BlogTagResource::collection($tags);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    /**
     * Category-wise rows for the landing page: each active category with its
     * newest posts, the way the reference magazine layout groups them.
     *
     * Built as one query over all categories rather than one query per
     * category — the per-category loop was an N+1 that grew with every
     * category an admin added.
     */
    public function sections(Request $request)
    {
        try {
            $perSection = max(1, min((int) $request->get('per_section', 4) ?: 4, 8));

            $categories = BlogCategory::query()
                ->active()
                ->withCount(['posts as published_posts_count' => fn($query) => $query->published()])
                // whereHas, not having('published_posts_count','>',0): filtering
                // on a withCount alias without a GROUP BY relies on a MySQL
                // extension and breaks under ONLY_FULL_GROUP_BY. This is a plain
                // EXISTS subquery — portable, and it can use the index.
                ->whereHas('posts', fn($query) => $query->published())
                ->orderBy('priority')
                ->orderBy('name')
                ->get();

            if ($categories->isEmpty()) {
                return response(['sections' => []]);
            }

            // One query per section, but bounded — NOT one unbounded fetch of
            // every published post. The previous version called ->get() with no
            // limit and then took $perSection from each group, which pulled the
            // entire blog (including every `content` longtext) into memory just
            // to display four cards per row.
            //
            // Two steps. First collect only the ids to show — one small
            // indexed lookup per category, each capped at $perSection. That is
            // a handful of bounded queries instead of one unbounded fetch, and
            // it avoids Laravel's unionAll gotcha where a limit on the base
            // query applies to the whole union rather than each branch.
            $ids = [];

            foreach ($categories as $category) {
                $ids = array_merge($ids, BlogPost::published()
                    ->where('blog_category_id', $category->id)
                    ->orderByDesc('published_at')
                    ->limit($perSection)
                    ->pluck('id')
                    ->all());
            }

            if (empty($ids)) {
                return response(['sections' => []]);
            }

            // Then hydrate just those rows, with their media and category.
            $posts = BlogPost::with(['media', 'category'])
                ->whereIn('id', $ids)
                ->orderByDesc('published_at')
                ->get()
                ->groupBy('blog_category_id');

            $sections = $categories->map(function ($category) use ($posts, $perSection) {
                $items = ($posts->get($category->id) ?? collect())->take($perSection);

                return [
                    'name'        => $category->name,
                    'slug'        => $category->slug,
                    'posts_count' => (int) $category->published_posts_count,
                    'posts'       => BlogCardResource::collection($items),
                ];
            })->filter(fn($section) => count($section['posts']) > 0)->values();

            return response(['sections' => $sections]);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    /**
     * Everything the blog landing page needs in one round trip: the hero post,
     * the recent grid, and the most-read sidebar list. Three separate requests
     * made the page wait on the slowest of them before anything rendered.
     */
    public function overview()
    {
        try {
            $featured = BlogPost::with(['media', 'category'])
                ->published()
                ->orderByDesc('is_featured')
                ->orderByDesc('published_at')
                ->first();

            $recent = BlogPost::with(['media', 'category'])
                ->published()
                ->when($featured, fn($query) => $query->where('id', '!=', $featured->id))
                ->orderByDesc('published_at')
                ->limit(6)
                ->get();

            $popular = BlogPost::with(['media', 'category'])
                ->published()
                ->orderByDesc('views')
                ->orderByDesc('published_at')
                ->limit(5)
                ->get();

            return response([
                'featured' => $featured ? new BlogCardResource($featured) : null,
                'recent'   => BlogCardResource::collection($recent),
                'popular'  => BlogCardResource::collection($popular),
            ]);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }
}
