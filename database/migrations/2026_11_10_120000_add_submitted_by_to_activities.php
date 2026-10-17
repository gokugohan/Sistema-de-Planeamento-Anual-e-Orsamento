<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /** Who prepared (submitted) the activity, so that the approval chain can keep preparer and approver apart. Existing rows stay empty. */
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->foreignId('submitted_by')->nullable()->after('submitted_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('submitted_by');
        });
    }
};
