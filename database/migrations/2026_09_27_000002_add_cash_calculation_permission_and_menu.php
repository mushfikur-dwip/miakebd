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
        // permissions.name - which is what breaks `migrate:fresh --seed`.
        if (DB::table('permissions')->count() === 0) {
            return;
        }

        // `cash-calculation` opens the page and lets staff record money;
        // `_balance` is what shows them any figure. Held apart so a cashier can
        // count the drawer without first seeing what it is meant to hold.
        $parentPermissionId = $this->permission('Cash Calculation', 'cash-calculation', 'cash-calculation', 0);
        $this->permission('Cash Calculation Balance', 'cash-calculation_balance', 'cash-calculation/balance', $parentPermissionId);

        // The deploy clears caches before it migrates, so without this the
        // cached permission list would not know the two rows above for a day.
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        if (DB::table('menus')->where('url', 'cash-calculation')->exists()) {
            return;
        }

        // Next to POS and POS Orders, where the money comes from. Falls back to
        // a top-level entry if that group was renamed.
        $parentId = DB::table('menus')->where('language', 'pos_and_orders')->where('url', '#')->value('id');

        DB::table('menus')->insert([
            'name'       => 'Cash Calculation',
            'language'   => 'cash_calculation',
            'url'        => 'cash-calculation',
            'icon'       => 'lab lab-line-transactions',
            'status'     => 1,
            'parent'     => $parentId ?: 0,
            'priority'   => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Insert one permission if it is missing and grant it to Admin and
     * Manager. Returns the permission id so a child can point `parent` at it.
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
        DB::table('menus')->where('url', 'cash-calculation')->delete();

        $permissionIds = DB::table('permissions')
            ->whereIn('name', ['cash-calculation', 'cash-calculation_balance'])
            ->pluck('id');

        if ($permissionIds->isNotEmpty()) {
            DB::table('role_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        }
    }
};
