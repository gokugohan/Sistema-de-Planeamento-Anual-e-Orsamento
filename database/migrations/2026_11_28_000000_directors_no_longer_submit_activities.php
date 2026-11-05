<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * A director checks what the unit prepares and no longer submits it: the one who prepares is not the one who checks.
 * A unit without a technical officer names its preparer in its approval list (Prepare). An administrator can give the
 * permission back to the role in System Users → Roles and permissions.
 */
return new class extends Migration
{
    public function up(): void
    {
        $role = Role::where('name', 'Director')->first();
        if ($role && Permission::where('name', 'submit_paa')->exists() && $role->hasPermissionTo('submit_paa')) {
            $role->revokePermissionTo('submit_paa');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $role = Role::where('name', 'Director')->first();
        if ($role && Permission::where('name', 'submit_paa')->exists()) {
            $role->givePermissionTo('submit_paa');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
