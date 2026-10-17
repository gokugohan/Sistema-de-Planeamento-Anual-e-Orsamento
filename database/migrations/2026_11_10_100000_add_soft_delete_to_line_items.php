<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * A budget line removed by a revision is set aside, not deleted, so that the amount in force stays until the
     * revision is approved. The column only marks such lines; nothing existing is changed or deleted.
     */
    public function up(): void
    {
        Schema::table('line_items', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    /**
     * Lines set aside by a pending revision are removed for good (the revision already removed them from the draft);
     * their amounts in force go with them, as they did before this change. Take a backup before rolling back.
     */
    public function down(): void
    {
        DB::table('line_items')->whereNotNull('deleted_at')->delete();

        Schema::table('line_items', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
