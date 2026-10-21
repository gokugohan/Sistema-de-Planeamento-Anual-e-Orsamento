<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * The funding sources every installation starts with: State Budget (the default), a development partner and own
     * revenue. They used to be inserted by the migration that created the table; the schema is now one baseline, so
     * the starting rows are added here. An installation that already has funding sources is left alone.
     */
    public function up(): void
    {
        if (DB::table('funding_sources')->exists()) {
            return;
        }

        $now = now();
        DB::table('funding_sources')->insert([
            ['code' => 'OGE', 'name' => 'State Budget', 'kind' => 'State', 'is_default' => true, 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'PARTNER', 'name' => 'Development partner', 'kind' => 'Partner', 'is_default' => false, 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'OWN', 'name' => 'Own revenue', 'kind' => 'Other', 'is_default' => false, 'active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        DB::table('funding_sources')->whereIn('code', ['OGE', 'PARTNER', 'OWN'])->delete();
    }
};
