<?php

use App\Enums\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // This is a data patch for installs that predate the permission. On a
        // fresh database the seeders own the permission and menu tables, and
        // inserting here first made PermissionTableSeeder collide on
        // permissions.name — so `migrate:fresh --seed` could not complete and
        // the project could not be stood up from scratch.
        if (DB::table('permissions')->count() === 0) {
            return;
        }

        $permissionId = DB::table('permissions')->where('name', 'store-sales-report')->value('id');

        if (!$permissionId) {
            $permissionId = DB::table('permissions')->insertGetId([
                'title'      => 'Store Sales Report',
                'name'       => 'store-sales-report',
                'guard_name' => 'sanctum',
                'url'        => 'store-sales-report',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $roleIds = [Role::ADMIN, 3];
        foreach ($roleIds as $roleId) {
            if (!DB::table('roles')->where('id', $roleId)->exists()) {
                continue;
            }

            $exists = DB::table('role_has_permissions')
                ->where('permission_id', $permissionId)
                ->where('role_id', $roleId)
                ->exists();

            if (!$exists) {
                DB::table('role_has_permissions')->insert([
                    'permission_id' => $permissionId,
                    'role_id'       => $roleId,
                ]);
            }
        }

        $reportsMenuId = DB::table('menus')->where('language', 'reports')->where('url', '#')->value('id');
        if ($reportsMenuId && !DB::table('menus')->where('url', 'store-sales-report')->exists()) {
            DB::table('menus')->insert([
                'name'       => 'Store Sales Report',
                'language'   => 'store_sales_report',
                'url'        => 'store-sales-report',
                'icon'       => 'lab lab-line-sales-report',
                'status'     => 1,
                'parent'     => $reportsMenuId,
                'priority'   => 100,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('menus')->where('url', 'store-sales-report')->delete();

        $permissionId = DB::table('permissions')->where('name', 'store-sales-report')->value('id');
        if ($permissionId) {
            DB::table('role_has_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
    }
};
