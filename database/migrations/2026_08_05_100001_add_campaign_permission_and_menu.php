<?php

use App\Enums\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Data patch for existing installs only. On a fresh database the
        // seeders own permissions/menus, and inserting here first collides on
        // permissions.name — which is what breaks `migrate:fresh --seed`.
        if (DB::table('permissions')->count() === 0) {
            return;
        }

        // Parent first — the sub-permissions carry its id in `parent`, which
        // is what groups them into one block in Settings -> Roles. Inserting
        // them flat leaves five ungrouped rows at the bottom of that screen.
        $parentPermissionId = $this->permission('Campaigns', 'campaigns', 'campaigns', 0);

        $children = [
            ['Campaigns Create', 'campaigns_create', 'campaigns/create'],
            ['Campaigns Edit', 'campaigns_edit', 'campaigns/edit'],
            ['Campaigns Delete', 'campaigns_delete', 'campaigns/delete'],
            ['Campaigns Show', 'campaigns_show', 'campaigns/show'],
        ];

        foreach ($children as [$title, $name, $url]) {
            $this->permission($title, $name, $url, $parentPermissionId);
        }

        if (DB::table('menus')->where('url', 'campaigns')->exists()) {
            return;
        }

        // Campaigns belongs under the existing Promo group next to Promotions
        // and Product Sections, not at the top level.
        $parentId = DB::table('menus')->where('language', 'promo')->where('url', '#')->value('id');

        if (!$parentId) {
            return;
        }

        DB::table('menus')->insert([
            'name'       => 'Campaigns',
            'language'   => 'campaigns',
            'url'        => 'campaigns',
            'icon'       => 'lab lab-line-promotion',
            'status'     => 1,
            'parent'     => $parentId,
            'priority'   => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Insert one permission if it is missing and grant it to Admin and
     * Manager — the two roles that already hold `promotions`. Returns the
     * permission id so children can point their `parent` at it.
     */
    private function permission(string $title, string $name, string $url, int $parent): int
    {
        $permissionId = DB::table('permissions')->where('name', $name)->value('id');

        if (!$permissionId) {
            $permissionId = DB::table('permissions')->insertGetId([
                'title'      => $title,
                'name'       => $name,
                'guard_name' => 'sanctum',
                'url'        => $url,
                'parent'     => $parent,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach ([Role::ADMIN, Role::MANAGER] as $roleId) {
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

        return (int) $permissionId;
    }

    public function down(): void
    {
        DB::table('menus')->where('url', 'campaigns')->delete();

        $permissionIds = DB::table('permissions')
            ->whereIn('name', ['campaigns', 'campaigns_create', 'campaigns_edit', 'campaigns_delete', 'campaigns_show'])
            ->pluck('id');

        if ($permissionIds->isNotEmpty()) {
            DB::table('role_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        }
    }
};
