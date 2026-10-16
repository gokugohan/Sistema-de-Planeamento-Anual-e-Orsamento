<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The implementation plan as the ministry's form has it: under each indicator several numbered actions, each
     * with the months it is carried out in; and the budget allocated per indicator, which is the sum of the budget
     * lines tied to it. What was kept as one text and one set of months per indicator becomes its first action.
     */
    public function up(): void
    {
        Schema::create('indicator_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('indicator_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(1);
            $table->text('text');
            $table->json('months')->nullable(); // 1..12; empty: follows the quarters of the indicator
            $table->timestamps();

            $table->index(['indicator_id', 'position']);
        });

        $now = now();
        foreach (DB::table('indicators')->where(fn ($q) => $q->whereNotNull('implementation')->orWhereNotNull('plan_months'))->get(['id', 'implementation', 'plan_months']) as $i) {
            DB::table('indicator_actions')->insert([
                'indicator_id' => $i->id, 'position' => 1, 'text' => $i->implementation ?: '—',
                'months' => $i->plan_months, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        Schema::table('indicators', function (Blueprint $table) {
            $table->dropColumn(['implementation', 'plan_months']);
        });

        Schema::table('line_items', function (Blueprint $table) {
            $table->foreignId('indicator_id')->nullable()->after('activity_id')->constrained('indicators')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('line_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('indicator_id');
        });
        Schema::table('indicators', function (Blueprint $table) {
            $table->text('implementation')->nullable();
            $table->json('plan_months')->nullable();
        });
        foreach (DB::table('indicator_actions')->orderBy('position')->get() as $a) {
            DB::table('indicators')->where('id', $a->indicator_id)->update(['implementation' => $a->text, 'plan_months' => $a->months]);
        }
        Schema::dropIfExists('indicator_actions');
    }
};
