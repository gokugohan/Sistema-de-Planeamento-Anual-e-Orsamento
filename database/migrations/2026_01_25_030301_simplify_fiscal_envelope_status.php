<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE fiscal_envelopes MODIFY status ENUM('Active','Closed','Draft','Approved','Pending Approval') NOT NULL DEFAULT 'Draft'");
        DB::table('fiscal_envelopes')->where('status', 'Active')->update(['status' => 'Approved']);
        DB::statement("ALTER TABLE fiscal_envelopes MODIFY status ENUM('Draft','Pending Approval','Approved','Closed') NOT NULL DEFAULT 'Draft'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('fiscal_envelopes')->where('status', 'Pending Approval')->update(['status' => 'Draft']);
        DB::table('fiscal_envelopes')->where('status', 'Approved')->update(['status' => 'Active']);
        DB::statement("ALTER TABLE fiscal_envelopes MODIFY status ENUM('Draft','Active','Closed') NOT NULL DEFAULT 'Draft'");
    }
};
