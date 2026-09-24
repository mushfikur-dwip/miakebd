<?php

namespace App\Services;

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
            // Ordered explicitly. Every seeded row carries priority 100, so
            // tie-breaking on id reproduces exactly the order the untidy
            // `Menu::get()` happened to return - while letting a row opt into a
            // higher priority to sit above its siblings.
            $menus           = Menu::orderBy('priority', 'desc')->orderBy('id')->get()->toArray();
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
}
