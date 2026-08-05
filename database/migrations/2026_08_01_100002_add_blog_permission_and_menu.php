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
        // permissions.name — which is what stops `migrate:fresh --seed`.
        if (DB::table('permissions')->count() === 0) {
            return;
        }

        $permissionId = DB::table('permissions')->where('name', 'blog')->value('id');

        if (!$permissionId) {
            $permissionId = DB::table('permissions')->insertGetId([
                'title'      => 'Blog',
                'name'       => 'blog',
                'guard_name' => 'sanctum',
                'url'        => 'blog',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Admin and Manager. A writer role can be granted this one permission
        // in Settings → Roles without also getting Settings access.
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

        if (DB::table('menus')->where('language', 'blog')->where('url', '#')->exists()) {
            return;
        }

        $parentId = DB::table('menus')->insertGetId([
            'name'       => 'Blog',
            'language'   => 'blog',
            'url'        => '#',
            'icon'       => 'lab lab-line-page',
            'status'     => 1,
            'parent'     => 0,
            'priority'   => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $children = [
            ['name' => 'All Posts', 'language' => 'blog_posts', 'url' => 'blog', 'icon' => 'lab lab-line-page'],
            ['name' => 'Blog Categories', 'language' => 'blog_categories', 'url' => 'blog-categories', 'icon' => 'lab lab-line-category'],
            ['name' => 'Concerns & Tags', 'language' => 'blog_tags', 'url' => 'blog-tags', 'icon' => 'lab lab-line-tag'],
        ];

        foreach ($children as $child) {
            if (DB::table('menus')->where('url', $child['url'])->exists()) {
                continue;
            }

            DB::table('menus')->insert($child + [
                'status'     => 1,
                'parent'     => $parentId,
                'priority'   => 100,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $parentId = DB::table('menus')->where('language', 'blog')->where('url', '#')->value('id');

        DB::table('menus')->whereIn('url', ['blog', 'blog-categories', 'blog-tags'])->delete();

        if ($parentId) {
            DB::table('menus')->where('id', $parentId)->delete();
        }

        $permissionId = DB::table('permissions')->where('name', 'blog')->value('id');

        if ($permissionId) {
            DB::table('role_has_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
    }
};
