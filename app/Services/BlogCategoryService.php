<?php

namespace App\Services;

use App\Http\Requests\BlogCategoryRequest;
use App\Http\Requests\PaginateRequest;
use App\Libraries\QueryExceptionLibrary;
use App\Models\BlogCategory;
use App\Support\BlogMetaResolver;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BlogCategoryService
{
    protected array $blogCategoryFilter = [
        'name',
        'slug',
        'status',
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

            return BlogCategory::with('media')
                ->withCount('posts')
                ->where(function ($query) use ($requests) {
                    foreach ($requests as $key => $request) {
                        if (!in_array($key, $this->blogCategoryFilter)) {
                            continue;
                        }

                        // Exact match on the status flag, the same fix
                        // BlogPostService has: `like '%10%'` silently matched
                        // other values whenever the enum changed.
                        if ($key === 'status') {
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
    public function store(BlogCategoryRequest $request): BlogCategory
    {
        try {
            $category = BlogCategory::create($request->validated() + [
                'slug' => $this->uniqueSlug($request->name),
            ]);

            if ($request->image) {
                $category->addMediaFromRequest('image')->toMediaCollection('blog-category-cover');
            }

            BlogMetaResolver::flush();

            return $category;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function update(BlogCategoryRequest $request, BlogCategory $blogCategory): BlogCategory
    {
        try {
            // The slug is deliberately NOT recomputed from the name on update.
            // Once a category page is indexed, renaming "Beauty Tips" to
            // "Beauty Tips & Tricks" would silently move the URL and throw away
            // every ranking signal it had earned.
            $blogCategory->update($request->validated());

            if ($request->image) {
                $blogCategory->clearMediaCollection('blog-category-cover');
                $blogCategory->addMediaFromRequest('image')->toMediaCollection('blog-category-cover');
            }

            BlogMetaResolver::flush();

            return $blogCategory;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function destroy(BlogCategory $blogCategory): void
    {
        try {
            $blogCategory->delete();
            BlogMetaResolver::flush();
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function show(BlogCategory $blogCategory): BlogCategory
    {
        try {
            return $blogCategory;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * Slugs are unique at the database level, so a duplicate name would throw a
     * raw SQL error instead of a usable message. Suffix instead.
     */
    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'category';
        $slug = $base;
        $suffix = 2;

        while (BlogCategory::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $suffix++;
        }

        return $slug;
    }
}
