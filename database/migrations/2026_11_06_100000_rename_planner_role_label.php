<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** The role that prepares a unit's activities is shown as "Technical Officer" (it was "Planner"); its internal name does not change. */
    public function up(): void
    {
        DB::table('roles')->where('name', 'Planning')->update([
            'display_name' => 'Technical Officer',
            'description' => 'Technical officer of a unit: prepares its activities, indicators and budget lines',
        ]);
    }

    public function down(): void
    {
        DB::table('roles')->where('name', 'Planning')->update(['display_name' => 'Planner', 'description' => 'Activity planner']);
    }
};
