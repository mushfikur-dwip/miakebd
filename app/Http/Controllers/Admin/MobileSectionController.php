<?php

namespace App\Http\Controllers\Admin;

use App\Enums\MenuType;
use App\Http\Requests\PaginateRequest;
use App\Models\Menu;
use App\Models\ThemeSetting;
use App\Services\MenuService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MobileSectionController extends AdminController
{
    private MenuService $menuService;

    public function __construct(MenuService $menuService)
    {
        parent::__construct();
        $this->menuService = $menuService;
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
            $request->validate([
                'image' => 'required|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
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
