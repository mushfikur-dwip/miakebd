<?php

namespace App\Services;

use App\Http\Requests\BlogPostRequest;
use App\Http\Requests\PaginateRequest;
use App\Libraries\QueryExceptionLibrary;
use App\Models\BlogPost;
use App\Support\BlogMetaResolver;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BlogPostService
{
    protected array $blogPostFilter = [
        'title',
        'slug',
        'status',
        'blog_category_id',
    ];

    /**
     * @throws Exception
     */
    public function list(PaginateRequest $request)
    {
        try {
            $requests    = $request->all();
            $method      = $request->get('paginate', 0) == 1 ? 'paginate' : 'get';
            $methodValue = $request->get('paginate', 0) == 1 ? $request->get('per_page', 10) : '*';
            $orderColumn = $request->get('order_column') ?? 'id';
            $orderType   = $request->get('order_type') ?? 'desc';

            return BlogPost::with(['media', 'category', 'tags'])
                ->where(function ($query) use ($requests) {
                    foreach ($requests as $key => $request) {
                        if (!in_array($key, $this->blogPostFilter)) {
                            continue;
                        }

                        // An exact match on the foreign key. `like %5%` also
                        // matched categories 15 and 50, so the category filter
                        // returned posts from unrelated categories.
                        if ($key === 'blog_category_id' || $key === 'status') {
                            $query->where($key, $request);
                            continue;
                        }

                        $query->where($key, 'like', '%' . $request . '%');
                    }
                })
                ->orderBy($orderColumn, $orderType)
                ->$method($methodValue);
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function store(BlogPostRequest $request): BlogPost
    {
        try {
            $validated = $request->validated();

            // All of these are set ON $validated, never through a `+` union.
            // The union keeps the LEFT array's value for duplicate keys, and
            // the form posts these fields as empty strings which the framework
            // converts to null — so `$validated + ['published_at' => now()]`
            // kept the null and the default never applied. A post saved with a
            // blank publish date then failed scopePublished()'s
            // whereNotNull('published_at') and stayed invisible on the public
            // site forever. Same trap the slug hit.
            $validated['slug'] = $this->uniqueSlug($validated['slug'] ?? null, $validated['title']);

            // Blank publish date means "publish now".
            if (blank($validated['published_at'] ?? null)) {
                $validated['published_at'] = now();
            }

            if (blank($validated['author_name'] ?? null)) {
                $validated['author_name'] = Auth::user()?->name;
            }

            $validated['created_by'] = Auth::id();

            // is_featured is `nullable` in the rules but NOT NULL in the
            // schema, so a blank checkbox would insert null and fail.
            $validated['is_featured'] = (bool) ($validated['is_featured'] ?? false);

            $post = BlogPost::create($validated);
            $this->syncTags($post, $request);

            if ($request->image) {
                $post->addMediaFromRequest('image')->toMediaCollection('blog-post-cover');
            }

            BlogMetaResolver::flush();

            return $post;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function update(BlogPostRequest $request, BlogPost $blogPost): BlogPost
    {
        try {
            $validated = $request->validated();

            // Only regenerate the slug when the admin actually typed one.
            // Retitling a live post must not move its URL — see the same
            // reasoning in BlogCategoryService::update().
            if (filled($validated['slug'] ?? null)) {
                $validated['slug'] = $this->uniqueSlug($validated['slug'], $validated['title'], $blogPost->id);
            } else {
                unset($validated['slug']);
            }

            if (blank($validated['published_at'] ?? null)) {
                unset($validated['published_at']);
            }

            $validated['is_featured'] = (bool) ($validated['is_featured'] ?? false);

            $blogPost->update($validated);
            $this->syncTags($blogPost, $request);

            if ($request->image) {
                $blogPost->clearMediaCollection('blog-post-cover');
                $blogPost->addMediaFromRequest('image')->toMediaCollection('blog-post-cover');
            }

            BlogMetaResolver::flush();

            return $blogPost;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function destroy(BlogPost $blogPost): void
    {
        try {
            $blogPost->delete();
            BlogMetaResolver::flush();
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function show(BlogPost $blogPost): BlogPost
    {
        try {
            return $blogPost->load(['category', 'tags']);
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * Attach the selected concerns.
     *
     * The form posts `tags` as a comma-separated id list, because FormData
     * flattens arrays awkwardly and `tags[]` keys arrive inconsistently across
     * the PATCH/POST spoofing this admin uses. Absent key means "not submitted"
     * and leaves existing tags alone; an empty string means "clear them".
     */
    private function syncTags(BlogPost $post, BlogPostRequest $request): void
    {
        if (!$request->has('tags')) {
            return;
        }

        $ids = collect(explode(',', (string) $request->input('tags')))
            ->map(fn($id) => (int) trim($id))
            ->filter()
            ->unique()
            ->all();

        $post->tags()->sync($ids);
    }

    private function uniqueSlug(?string $preferred, string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($preferred ?: $title) ?: 'post';
        $slug = $base;
        $suffix = 2;

        while (
            BlogPost::where('slug', $slug)
                ->when($ignoreId, fn($query) => $query->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = $base . '-' . $suffix++;
        }

        return $slug;
    }
}
