<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /** A directorate may close its year by declaring that it has nothing to plan (an optional rule). Existing rows are plans. */
    public function up(): void
    {
        Schema::table('unit_plannings', function (Blueprint $table) {
            $table->boolean('nil_return')->default(false)->after('planning_remark');
        });
    }

    public function down(): void
    {
        Schema::table('unit_plannings', function (Blueprint $table) {
            $table->dropColumn('nil_return');
        });
    }
};
