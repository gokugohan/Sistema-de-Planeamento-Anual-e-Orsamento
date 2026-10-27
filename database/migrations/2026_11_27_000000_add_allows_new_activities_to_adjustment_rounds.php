<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A round may let its directorates add new activities (a supplementary budget with new money) until it closes; an
 * activity created that way is kept with the round as new (is_new), next to the ones revised for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('adjustment_rounds', function (Blueprint $table) {
            $table->boolean('allows_new_activities')->default(false)->after('instructions');
        });
        Schema::table('adjustment_round_activities', function (Blueprint $table) {
            $table->boolean('is_new')->default(false)->after('activity_id');
        });
    }

    public function down(): void
    {
        Schema::table('adjustment_round_activities', function (Blueprint $table) {
            $table->dropColumn('is_new');
        });
        Schema::table('adjustment_rounds', function (Blueprint $table) {
            $table->dropColumn('allows_new_activities');
        });
    }
};
