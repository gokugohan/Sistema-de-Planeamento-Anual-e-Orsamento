<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The Director role is the head of a unit at any level (chefe de unidade or departamento, diretor, diretor-geral,
 * chefe de gabinete) and reads "Head". A head checks what the unit prepares: no creating, editing or deleting of
 * activities (submitting was taken away in 2026_11_28). An administrator can give any of it back in System Users →
 * Roles and permissions.
 */
return new class extends Migration
{
    private const PREPARING = ['create_activities', 'edit_activities', 'delete_activities'];

    public function up(): void
    {
        if (! $role = Role::where('name', 'Director')->first()) {
            return;
        }
        foreach (self::PREPARING as $permission) {
            if ($role->checkPermissionTo($permission)) {
                $role->revokePermissionTo($permission);
            }
        }
        $role->forceFill(['display_name' => 'Head', 'description' => 'The head of a unit at any level (chefe de unidade or departamento, diretor, diretor-geral, chefe de gabinete): certifies, validates or approves by the level of the unit'])->save();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (! $role = Role::where('name', 'Director')->first()) {
            return;
        }
        $role->givePermissionTo(['create_activities', 'edit_activities']);
        $role->forceFill(['display_name' => 'Director', 'description' => 'Directorate director with planning and monitoring authority'])->save();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
