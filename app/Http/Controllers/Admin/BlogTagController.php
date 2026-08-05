<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\BlogTagRequest;
use App\Http\Requests\PaginateRequest;
use App\Http\Resources\BlogTagResource;
use App\Models\BlogTag;
use App\Services\BlogTagService;
use Exception;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class BlogTagController extends AdminController implements HasMiddleware
{
    private BlogTagService $blogTagService;

    public function __construct(BlogTagService $blogTagService)
    {
        parent::__construct();
        $this->blogTagService = $blogTagService;
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
            return BlogTagResource::collection($this->blogTagService->list($request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function store(BlogTagRequest $request)
    {
        try {
            return new BlogTagResource($this->blogTagService->store($request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function show(BlogTag $blogTag)
    {
        try {
            return new BlogTagResource($this->blogTagService->show($blogTag));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function update(BlogTagRequest $request, BlogTag $blogTag)
    {
        try {
            return new BlogTagResource($this->blogTagService->update($request, $blogTag));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function destroy(BlogTag $blogTag)
    {
        try {
            $this->blogTagService->destroy($blogTag);

            return response('', 202);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }
}
