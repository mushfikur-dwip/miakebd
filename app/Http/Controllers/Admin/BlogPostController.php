<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\BlogPostRequest;
use App\Http\Requests\PaginateRequest;
use App\Http\Resources\BlogPostResource;
use App\Models\BlogPost;
use App\Services\BlogPostService;
use Exception;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class BlogPostController extends AdminController implements HasMiddleware
{
    private BlogPostService $blogPostService;

    public function __construct(BlogPostService $blogPostService)
    {
        parent::__construct();
        $this->blogPostService = $blogPostService;
    }

    public static function middleware(): array
    {
        return [
            new Middleware('permission:blog', only: ['index', 'store', 'update', 'destroy', 'show']),
        ];
    }

    public function index(PaginateRequest $request)
    {
        try {
            return BlogPostResource::collection($this->blogPostService->list($request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function store(BlogPostRequest $request)
    {
        try {
            return new BlogPostResource($this->blogPostService->store($request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function show(BlogPost $blogPost)
    {
        try {
            return new BlogPostResource($this->blogPostService->show($blogPost));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function update(BlogPostRequest $request, BlogPost $blogPost)
    {
        try {
            return new BlogPostResource($this->blogPostService->update($request, $blogPost));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function destroy(BlogPost $blogPost)
    {
        try {
            $this->blogPostService->destroy($blogPost);

            return response('', 202);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }
}
