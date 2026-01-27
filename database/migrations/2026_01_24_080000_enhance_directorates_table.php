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
            $table->string('type')->nullable()->after('name'); // e.g., National Directorate, Department
            $table->text('description')->nullable()->after('type');
            $table->string('director_name')->nullable()->after('description');
            $table->string('director_email')->nullable()->after('director_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('directorates', function (Blueprint $table) {
            $table->dropColumn(['type', 'description', 'director_name', 'director_email']);
        });
    }
};
