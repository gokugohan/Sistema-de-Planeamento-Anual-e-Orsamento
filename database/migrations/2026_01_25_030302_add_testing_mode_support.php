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
        // Add is_test_data column to activities table
        Schema::table('activities', function (Blueprint $table) {
            $table->boolean('is_test_data')->default(false)->after('id');
            $table->index('is_test_data'); // Index for query performance
        });

        // Add is_test_data column to line_items table
        Schema::table('line_items', function (Blueprint $table) {
            $table->boolean('is_test_data')->default(false)->after('id');
            $table->index('is_test_data');
        });

        // Add is_test_data column to fiscal_envelopes table
        Schema::table('fiscal_envelopes', function (Blueprint $table) {
            $table->boolean('is_test_data')->default(false)->after('id');
            $table->index('is_test_data');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropIndex(['is_test_data']);
            $table->dropColumn('is_test_data');
        });

        Schema::table('line_items', function (Blueprint $table) {
            $table->dropIndex(['is_test_data']);
            $table->dropColumn('is_test_data');
        });

        Schema::table('fiscal_envelopes', function (Blueprint $table) {
            $table->dropIndex(['is_test_data']);
            $table->dropColumn('is_test_data');
        });
    }
};
