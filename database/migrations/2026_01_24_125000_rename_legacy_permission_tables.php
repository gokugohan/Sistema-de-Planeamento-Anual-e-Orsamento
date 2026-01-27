<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::rename('roles', 'legacy_roles');
        Schema::rename('permissions', 'legacy_permissions');
        Schema::rename('role_permissions', 'legacy_role_permissions');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::rename('legacy_roles', 'roles');
        Schema::rename('legacy_permissions', 'permissions');
        Schema::rename('legacy_role_permissions', 'role_permissions');
    }
};
