<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Signatures are versioned and never deleted. Each activity has rounds: a round is the run of signatures between
     * the activity being submitted and being returned or reopened. A signature is 'active' while its round runs,
     * 'completed' once the last name signed and the activity moved on, and 'voided' (with who and why) when the activity
     * was returned or reopened; the next signatures then belong to the next round. So who signed before a reset is
     * always on record, and the same person can sign again in the new round.
     */
    public function up(): void
    {
        Schema::table('activity_signatures', function (Blueprint $table) {
            $table->unsignedSmallInteger('round')->default(1)->after('stage');
            $table->string('state', 10)->default('active')->after('signed_by'); // active | completed | voided
            $table->timestamp('closed_at')->nullable()->after('state');
            $table->foreignId('closed_by')->nullable()->after('closed_at')->constrained('users')->nullOnDelete();
            $table->string('close_reason', 255)->nullable()->after('closed_by');
        });
        Schema::table('activity_signatures', function (Blueprint $table) {
            $table->index('activity_id'); // the foreign key needs its own index before the unique one can go
        });
        Schema::table('activity_signatures', function (Blueprint $table) {
            $table->dropUnique(['activity_id', 'stage', 'user_id']);
            $table->index(['activity_id', 'round', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::table('activity_signatures', function (Blueprint $table) {
            $table->dropIndex(['activity_id', 'round', 'stage']);
            $table->dropConstrainedForeignId('closed_by');
            $table->dropColumn(['round', 'state', 'closed_at', 'close_reason']);
        });
    }
};
