<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which build of the application (App\Support\AppVersion::build(), e.g. 1.4.0+3.e54f2f1) wrote each audit row,
 * so a change can be traced to the version that made it. Rows written before this are left empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('app_version', 40)->nullable()->after('user_agent');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropColumn('app_version');
        });
    }
};
