<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The plan is the centre and there is no fiscal envelope any more: the permissions to create, edit, approve and
     * activate one go. (The old envelope tables are left in place, untouched, so nothing recorded is lost.)
     */
    public function up(): void
    {
        $names = ['create_fiscal_envelope', 'edit_fiscal_envelope', 'approve_fiscal_envelope', 'activate_fiscal_envelope'];
        $ids = DB::table('permissions')->whereIn('name', $names)->pluck('id');
        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        // the permissions are not brought back: the envelope pages no longer exist
    }
};
