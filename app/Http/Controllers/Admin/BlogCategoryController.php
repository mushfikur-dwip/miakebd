<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\BlogCategoryRequest;
use App\Http\Requests\PaginateRequest;
use App\Http\Resources\BlogCategoryResource;
use App\Models\BlogCategory;
use App\Services\BlogCategoryService;
use Exception;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class BlogCategoryController extends AdminController implements HasMiddleware
{
    private BlogCategoryService $blogCategoryService;

    public function __construct(BlogCategoryService $blogCategoryService)
    {
        parent::__construct();
        $this->blogCategoryService = $blogCategoryService;
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
            return BlogCategoryResource::collection($this->blogCategoryService->list($request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function store(BlogCategoryRequest $request)
    {
        try {
            return new BlogCategoryResource($this->blogCategoryService->store($request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function show(BlogCategory $blogCategory)
    {
        try {
            return new BlogCategoryResource($this->blogCategoryService->show($blogCategory));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function update(BlogCategoryRequest $request, BlogCategory $blogCategory)
    {
        try {
            return new BlogCategoryResource($this->blogCategoryService->update($request, $blogCategory));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function destroy(BlogCategory $blogCategory)
    {
        try {
            $this->blogCategoryService->destroy($blogCategory);

            return response('', 202);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }
}
