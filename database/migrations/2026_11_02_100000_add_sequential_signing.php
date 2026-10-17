<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A list of certifiers or approvers can be made "in order": everyone named signs, one after the other as
     * they are listed, and the activity moves on only after the last signature. A list that is not in order keeps
     * working as before (anyone named may act). The signatures of a stage live in activity_signatures and are
     * dropped when the activity moves on, is returned or is reopened.
     */
    public function up(): void
    {
        Schema::table('approver_assignments', function (Blueprint $table) {
            $table->boolean('sequential')->default(false)->after('position');
        });

        Schema::create('activity_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->string('stage', 30); // the status the activity was in while signed
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // the listed person whose turn it was
            $table->foreignId('signed_by')->nullable()->constrained('users')->nullOnDelete(); // who actually signed (an interim may stand in)
            $table->timestamp('signed_at')->nullable();
            $table->timestamps();

            $table->unique(['activity_id', 'stage', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_signatures');
        Schema::table('approver_assignments', fn (Blueprint $table) => $table->dropColumn('sequential'));
    }
};
