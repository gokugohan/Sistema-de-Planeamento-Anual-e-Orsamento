<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->text('objective')->nullable()->after('description');          // the result the activity is meant to achieve
            $table->string('location')->nullable()->after('manager_name');        // municipality / region where it is carried out
            $table->boolean('gender_responsive')->default(false)->after('procurement_required'); // gender-responsive budgeting marker
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropColumn(['objective', 'location', 'gender_responsive']);
        });
    }
};
