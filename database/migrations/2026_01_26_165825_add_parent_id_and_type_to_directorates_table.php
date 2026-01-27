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
            if (!Schema::hasColumn('directorates', 'parent_id')) {
                $table->foreignId('parent_id')->nullable()->constrained('directorates')->onDelete('cascade');
            }
            if (!Schema::hasColumn('directorates', 'type')) {
                $table->enum('type', ['General Directorate', 'National Directorate', 'Department', 'Unit', 'Independent'])->default('Independent');
            } else {
                // If it exists, we might want to change it to enum if it was string, but for now let's just leave it if it exists
                // to avoid the duplicate error
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('directorates', function (Blueprint $table) {
            if (Schema::hasColumn('directorates', 'parent_id')) {
                $table->dropForeign(['parent_id']);
                $table->dropColumn('parent_id');
            }
            // We only drop 'type' if we are sure we want to remove it, but it was already there in another migration.
            // However, to keep it clean:
            if (Schema::hasColumn('directorates', 'type')) {
                // $table->dropColumn('type'); // Skip dropping if it was part of another migration's rollback
            }
        });
    }
};
