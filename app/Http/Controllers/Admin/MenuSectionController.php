<?php

namespace App\Http\Controllers\Admin;


use Exception;
use App\Http\Requests\PaginateRequest;
use App\Http\Resources\MenuSectionResource;
use App\Services\MenuSectionService;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class MenuSectionController extends AdminController implements HasMiddleware
{

    private MenuSectionService $menuSectionService;

    public function __construct(MenuSectionService $menuSection)
    {
        parent::__construct();
        $this->menuSectionService = $menuSection;
    }

    // Read from Settings → Pages, which the router guards with "settings".
    public static function middleware(): array
    {
        return [
            new Middleware('permission:settings', only: ['index']),
        ];
    }

    public function index(PaginateRequest $request) : \Illuminate\Http\Response | \Illuminate\Http\Resources\Json\AnonymousResourceCollection | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return MenuSectionResource::collection($this->menuSectionService->list($request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }
}
