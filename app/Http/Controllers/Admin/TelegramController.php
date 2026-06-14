<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\TelegramRequest;
use App\Http\Resources\TelegramResource;
use App\Services\TelegramSettingService;
use Exception;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class TelegramController extends AdminController implements HasMiddleware
{
    private TelegramSettingService $telegramSettingService;

    public function __construct(TelegramSettingService $telegramSettingService)
    {
        parent::__construct();
        $this->telegramSettingService = $telegramSettingService;
    }

    public static function middleware(): array
    {
        return [
            new Middleware('permission:settings', only: ['index', 'update']),
        ];
    }

    public function index(): TelegramResource|Response|Application|ResponseFactory
    {
        try {
            return new TelegramResource($this->telegramSettingService->list());
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function update(TelegramRequest $request): TelegramResource|Response|Application|ResponseFactory
    {
        try {
            return new TelegramResource($this->telegramSettingService->update($request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }
}
