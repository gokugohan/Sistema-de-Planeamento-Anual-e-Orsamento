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
        Schema::table('users', function (Blueprint $table) {
            // Drop foreign key if it exists
            // We use try-catch or check because SQLite handles this differently
            try {
                $table->dropForeign(['role_id']);
            } catch (\Exception $e) {
            }

            $table->dropColumn(['role', 'role_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['Admin', 'Minister', 'Director', 'Budget', 'Planning', 'Viewer'])->default('Viewer')->after('password');
            $table->foreignId('role_id')->nullable()->after('directorate_id')->constrained()->onDelete('set null');
        });
    }
};
