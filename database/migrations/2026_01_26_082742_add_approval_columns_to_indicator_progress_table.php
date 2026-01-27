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
        Schema::table('indicator_progress', function (Blueprint $table) {
            $table->string('status')->default('Draft')->after('achieved_value');
            $table->foreignId('submitted_by')->nullable()->constrained('users')->onDelete('set null')->after('status');
            $table->foreignId('verified_by')->nullable()->constrained('users')->onDelete('set null')->after('submitted_by');
            $table->timestamp('verified_at')->nullable()->after('verified_by');
            $table->text('rejection_notes')->nullable()->after('verified_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('indicator_progress', function (Blueprint $table) {
            $table->dropColumn(['status', 'submitted_by', 'verified_by', 'verified_at', 'rejection_notes']);
        });
    }
};
