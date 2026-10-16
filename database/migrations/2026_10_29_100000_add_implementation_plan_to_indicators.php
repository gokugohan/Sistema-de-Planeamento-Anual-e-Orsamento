<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The implementation plan of an indicator: what will be done, and in which months. The quarters in which
     * it is reported follow from the months when they are given.
     */
    public function up(): void
    {
        Schema::table('indicators', function (Blueprint $table) {
            $table->text('implementation')->nullable();
            $table->json('plan_months')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('indicators', function (Blueprint $table) {
            $table->dropColumn(['implementation', 'plan_months']);
        });
    }
};
