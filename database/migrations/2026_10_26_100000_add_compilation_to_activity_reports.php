<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The quarter's text as the ministry prints it: drafted from the three monthly reports and the unit's own
     * quarterly report, then edited and signed off by the director of the unit. It lives beside the unit's own
     * text, so what the unit wrote is never overwritten.
     */
    public function up(): void
    {
        Schema::table('activity_reports', function (Blueprint $table) {
            $table->text('compiled_results')->nullable()->after('rating');
            $table->text('compiled_constraints')->nullable()->after('compiled_results');
            $table->foreignId('compiled_by')->nullable()->after('compiled_constraints')->constrained('users')->nullOnDelete();
            $table->timestamp('compiled_at')->nullable()->after('compiled_by');
        });
    }

    public function down(): void
    {
        Schema::table('activity_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('compiled_by');
            $table->dropColumn(['compiled_results', 'compiled_constraints', 'compiled_at']);
        });
    }
};
