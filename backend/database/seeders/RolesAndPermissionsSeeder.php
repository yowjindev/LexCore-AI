<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (['admin', 'manager', 'staff', 'superadmin'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $analyze = Permission::firstOrCreate(['name' => 'documents.ai.analyze', 'guard_name' => 'web']);
        $chat    = Permission::firstOrCreate(['name' => 'documents.ai.chat',    'guard_name' => 'web']);
        $search  = Permission::firstOrCreate(['name' => 'documents.search.semantic', 'guard_name' => 'web']);

        Role::findByName('admin')->syncPermissions([$analyze, $chat, $search]);
        Role::findByName('manager')->syncPermissions([$analyze, $chat, $search]);
        Role::findByName('staff')->syncPermissions([$chat, $search]);
        Role::findByName('superadmin')->syncPermissions([$analyze, $chat, $search]);
    }
}
