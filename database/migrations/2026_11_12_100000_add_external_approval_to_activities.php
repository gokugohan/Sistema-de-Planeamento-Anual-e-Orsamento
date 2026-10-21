<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * A revision can be marked as needing an approval outside the institution (an optional rule); it is then approved
     * only once the reference of that approval is recorded. Existing activities are not marked.
     */
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->boolean('external_approval_required')->default(false)->after('revision_reason');
            $table->string('external_approval_reference', 255)->nullable()->after('external_approval_required');
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropColumn(['external_approval_required', 'external_approval_reference']);
        });
    }
};
