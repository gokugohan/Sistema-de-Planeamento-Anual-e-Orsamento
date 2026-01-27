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
        Schema::table('directorates', function (Blueprint $table) {
            $table->integer('established_year')->nullable()->after('active');
            $table->integer('dissolved_year')->nullable()->after('established_year');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('directorates', function (Blueprint $table) {
            $table->dropColumn(['established_year', 'dissolved_year']);
        });
    }
};
