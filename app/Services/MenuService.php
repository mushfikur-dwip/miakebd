<?php

namespace App\Services;

use App\Enums\MenuType;
use App\Libraries\AppLibrary;
use App\Libraries\QueryExceptionLibrary;
use App\Models\Menu;
use Exception;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class MenuService
{
    /**
     * @throws Exception
     */
    public function menu(Role $role): array
    {
        try {
            $menus           = $this->orderedMenus();
            $permissions     = Permission::get();
            $rolePermissions = Permission::join(
                "role_has_permissions",
                "role_has_permissions.permission_id",
                "=",
                "permissions.id"
            )->where("role_has_permissions.role_id", $role->id)->get()->pluck('name', 'id');
            $permissions     = AppLibrary::permissionWithAccess($permissions, $rolePermissions);
            $permissions     = AppLibrary::pluck($permissions, 'obj', 'url');
            return AppLibrary::numericToAssociativeArrayBuilder(AppLibrary::menu($menus, $permissions));
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * The sidebar rows, flat, in the exact order the tree builder needs.
     *
     * AppLibrary::numericToAssociativeArrayBuilder walks the array once and
     * remembers only the most recently seen top-level row, so a child is
     * attached only when it appears *after* its own parent. A plain
     * `ORDER BY priority DESC` breaks that: a child with a raised priority
     * sorts ahead of every parent and is dropped from the menu without a
     * word. The grouping is therefore done here - each section, then its own
     * children - which is the shape that builder was written for.
     *
     * Sections keep their id order, which is the order the seeder wrote and
     * what the old unordered `Menu::get()` returned in practice. Within a
     * section a higher priority floats a row to the top; every seeded row
     * shares priority 100 and so keeps its id order.
     */
    private function orderedMenus(): array
    {
        // MOBILE_SECTION rows are storefront buttons added on the Mobile
        // Section page. They carry no `language`, so any that reached this
        // list rendered in the sidebar as a stray "Menu.Null".
        $menus = Menu::where('type', MenuType::BACKEND)->orderBy('id')->get();

        $children = $menus->filter(fn($menu) => (int) $menu->parent !== 0)
            ->sortBy([['priority', 'desc'], ['id', 'asc']])
            ->groupBy('parent');

        $ordered = [];

        foreach ($menus->filter(fn($menu) => (int) $menu->parent === 0) as $section) {
            $ordered[] = $section->toArray();

            foreach ($children->get($section->id, collect()) as $child) {
                $ordered[] = $child->toArray();
            }
        }

        return $ordered;
    }
}
