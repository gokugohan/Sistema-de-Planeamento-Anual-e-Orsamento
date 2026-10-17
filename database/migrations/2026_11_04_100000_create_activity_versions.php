<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The plan is the centre: there is no fiscal envelope and no distribution. What an approved activity may spend
     * is what its budget lines say in its approved version.
     *
     * Every approval of an activity makes a version (1, 2, ...), kept in activity_versions with a copy of what was
     * approved: the activity, its indicators and actions, and its budget lines. Changing an approved activity is a
     * revision: it gets a reason, goes back to Draft and through the whole approval chain, and while it does the
     * last approved version stays in force. adjusted_budgets now holds, per budget line, the amount of the approved
     * version in force (it was written by the distribution of the envelope before).
     *
     * Existing data: an approved activity becomes version 1, approved by whoever approved it, and its approved
     * amounts become what its lines plan. Amounts left from an earlier distribution on activities that are not
     * approved are removed.
     */
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->unsignedSmallInteger('version')->default(0)->after('status'); // the last approved version, 0 if never approved
            $table->text('revision_reason')->nullable()->after('version');        // why the revision in progress was started
        });

        Schema::create('activity_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('version');
            $table->longText('snapshot');                 // JSON: the activity, its indicators with actions, its budget lines
            $table->decimal('total', 15, 2)->default(0);
            $table->text('reason')->nullable();           // why this version was made (none for the first)
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['activity_id', 'version']);
        });

        $now = now();
        $approved = DB::table('activities')->where('status', 'Approved')->get();
        foreach ($approved as $a) {
            $lines = DB::table('line_items as li')->leftJoin('economic_classifications as ec', 'ec.id', '=', 'li.economic_classification_id')
                ->leftJoin('funding_sources as fs', 'fs.id', '=', 'li.funding_source_id')
                ->where('li.activity_id', $a->id)
                ->get(['li.id', 'li.economic_classification_id', 'ec.code as class_code', 'ec.name as class_name', 'li.funding_source_id', 'fs.name as source_name', 'li.indicator_id', 'li.estimated_amount']);
            $indicators = DB::table('indicators')->where('activity_id', $a->id)->orderBy('id')->get(['id', 'name', 'type', 'unit', 'annual_target', 'baseline', 'means_of_verification', 'due_quarters']);
            $actions = DB::table('indicator_actions')->whereIn('indicator_id', $indicators->pluck('id'))->orderBy('position')->get(['indicator_id', 'position', 'text', 'months'])->groupBy('indicator_id');
            $snapshot = [
                'activity' => ['code' => $a->code, 'description' => $a->description, 'objective' => $a->objective, 'directorate_id' => $a->directorate_id,
                    'program_id' => $a->program_id, 'subprogram_id' => $a->subprogram_id, 'priority' => $a->priority, 'planned_start' => $a->planned_start,
                    'planned_end' => $a->planned_end, 'execution_quarters' => json_decode((string) $a->execution_quarters, true), 'manager_name' => $a->manager_name],
                'indicators' => $indicators->map(fn ($i) => (array) $i + ['actions' => ($actions[$i->id] ?? collect())->map(fn ($x) => ['position' => $x->position, 'text' => $x->text, 'months' => json_decode((string) $x->months, true)])->values()->all()])->all(),
                'lines' => $lines->map(fn ($l) => (array) $l)->all(),
            ];
            DB::table('activity_versions')->insert([
                'activity_id' => $a->id, 'version' => 1, 'snapshot' => json_encode($snapshot), 'total' => $lines->sum('estimated_amount'),
                'approved_by' => $a->approved_by, 'approved_at' => $a->approved_at ?? $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('activities')->where('id', $a->id)->update(['version' => 1]);
            foreach ($lines as $l) {
                DB::table('adjusted_budgets')->updateOrInsert(['line_item_id' => $l->id], ['approved_amount' => $l->estimated_amount, 'status' => 'Approved', 'updated_at' => $now, 'created_at' => $now]);
            }
        }
        DB::table('adjusted_budgets')->whereNotIn('line_item_id', DB::table('line_items')->join('activities', 'activities.id', '=', 'line_items.activity_id')->where('activities.status', 'Approved')->select('line_items.id'))->delete();
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_versions');
        Schema::table('activities', fn (Blueprint $table) => $table->dropColumn(['version', 'revision_reason']));
    }
};
