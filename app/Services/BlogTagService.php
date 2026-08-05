<?php

namespace App\Services;

use App\Http\Requests\BlogTagRequest;
use App\Http\Requests\PaginateRequest;
use App\Libraries\QueryExceptionLibrary;
use App\Models\BlogTag;
use App\Support\BlogMetaResolver;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BlogTagService
{
    protected array $blogTagFilter = [
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
            $orderColumn = $request->get('order_column') ?? 'priority';
            $orderType   = $request->get('order_type') ?? 'asc';

            return BlogTag::withCount('posts')
                ->where(function ($query) use ($requests) {
                    foreach ($requests as $key => $request) {
                        if (!in_array($key, $this->blogTagFilter)) {
                            continue;
                        }

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
    public function store(BlogTagRequest $request): BlogTag
    {
        try {
            $validated = $request->validated();
            $validated['slug'] = $this->uniqueSlug($validated['name']);
            // priority is `nullable` in the rules but NOT NULL in the schema.
            $validated['priority'] = (int) ($validated['priority'] ?? 0);

            $tag = BlogTag::create($validated);

            BlogMetaResolver::flush();

            return $tag;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function update(BlogTagRequest $request, BlogTag $blogTag): BlogTag
    {
        try {
            $validated = $request->validated();
            // Slug intentionally not regenerated from the name — renaming a
            // concern must not move its indexed URL.
            $validated['priority'] = (int) ($validated['priority'] ?? 0);

            $blogTag->update($validated);

            BlogMetaResolver::flush();

            return $blogTag;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function destroy(BlogTag $blogTag): void
    {
        try {
            // Pivot rows go with it via cascadeOnDelete; posts are untouched.
            $blogTag->delete();
            BlogMetaResolver::flush();
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function show(BlogTag $blogTag): BlogTag
    {
        try {
            return $blogTag;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'tag';
        $slug = $base;
        $suffix = 2;

        while (BlogTag::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $suffix++;
        }

        return $slug;
    }
}
