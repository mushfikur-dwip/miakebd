<?php

namespace App\Http\Controllers\Admin;

use App\Enums\MenuType;
use App\Http\Requests\PaginateRequest;
use App\Models\Menu;
use App\Models\ThemeSetting;
use App\Services\MenuService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;

class MobileSectionController extends AdminController implements HasMiddleware
{
    private MenuService $menuService;

    public function __construct(MenuService $menuService)
    {
        parent::__construct();
        $this->menuService = $menuService;
    }

    /**
     * The write methods change what every storefront visitor sees, so they need
     * the same permission as the screen that drives them - Settings → Mobile
     * Section, permissionUrl "settings". Without this, /api/admin only asking
     * for auth:sanctum meant any logged-in customer could rewrite the mobile
     * navigation or replace its background image.
     *
     * index is deliberately left open: the storefront calls the same method
     * through GET /api/frontend/mobile-section to render the bar.
     */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:settings', only: ['storeButton', 'updateButton', 'deleteButton', 'updateBackground']),
        ];
    }

    public function index(PaginateRequest $request)
    {
        try {
            $buttons = Menu::where('type', MenuType::MOBILE_SECTION)->get();
            $themeSetting = ThemeSetting::where('key', 'theme-mobile-section-bg')->first();
            $background = $themeSetting ? $themeSetting->getFirstMediaUrl('theme-mobile-section-bg') : null;

            return response()->json([
                'status' => true,
                'data' => [
                    'buttons' => $buttons,
                    'background' => $background
                ]
            ]);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function storeButton(Request $request)
    {
        try {
            $request->validate([
                'name' => 'required|string',
                'url' => 'required|string',
            ]);

            $menu = Menu::create([
                'name' => $request->name,
                'url'  => $request->url,
                'type' => MenuType::MOBILE_SECTION,
                'status' => 1,
                'priority' => 100,
                'icon' => ''
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Button added successfully',
                'data' => $menu
            ]);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function updateButton(Request $request, $id)
    {
        try {
            $request->validate([
                'name' => 'required|string',
                'url' => 'required|string',
            ]);

            $menu = Menu::findOrFail($id);
            $menu->update([
                'name' => $request->name,
                'url'  => $request->url,
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Button updated successfully',
                'data' => $menu
            ]);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function deleteButton($id)
    {
        try {
            Menu::destroy($id);
            return response()->json([
                'status' => true,
                'message' => 'Button deleted successfully'
            ]);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function updateBackground(Request $request)
    {
        try {
            // svg removed on purpose. An SVG is an XML document that may carry
            // <script>, and this file is served back to every storefront
            // visitor from the same origin - uploading one is stored XSS
            // against the whole site. Laravel's `image` rule accepts svg, so
            // it has to be excluded here.
            $request->validate([
                'image' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
            ]);
            
            DB::transaction(function () use ($request) {
                $themeSetting = ThemeSetting::firstOrCreate(
                    ['key' => 'theme-mobile-section-bg'],
                    ['payload' => json_encode(['$value' => '', '$cast' => null])]
                );
                
                if ($request->hasFile('image')) {
                    $themeSetting->clearMediaCollection('theme-mobile-section-bg');
                    $themeSetting->addMediaFromRequest('image')->toMediaCollection('theme-mobile-section-bg');
                }
            });

            return response()->json([
                'status' => true,
                'message' => 'Background updated successfully'
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response(['status' => false, 'message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        } catch (\Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }
}
