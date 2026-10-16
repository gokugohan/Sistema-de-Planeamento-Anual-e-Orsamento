<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Planning ends per directorate: the head of a top-level unit finalizes the plan of their own directorate (and
     * the units below it) when everything there has been decided. A row means "finalized"; deleting it reopens.
     * The year counts as ended when every directorate that planned has finalized, or the budget has been received.
     * A year that was finalized as a whole before this change becomes one row per directorate that had activities.
     */
    public function up(): void
    {
        Schema::create('unit_plannings', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('fiscal_year');
            $table->foreignId('directorate_id')->constrained()->cascadeOnDelete(); // the top-level unit
            $table->foreignId('planning_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('planning_at')->nullable();
            $table->text('planning_remark')->nullable();
            $table->timestamps();

            $table->unique(['fiscal_year', 'directorate_id']);
        });

        $parents = DB::table('directorates')->pluck('parent_id', 'id');
        $rootOf = function (int $id) use ($parents) {
            for ($n = 0; $n < 20 && ($parents[$id] ?? null); $n++) {
                $id = (int) $parents[$id];
            }

            return $id;
        };
        $now = now();
        foreach (DB::table('budget_cycles')->where('planning_state', 'closed')->get(['fiscal_year', 'planning_by', 'planning_at', 'planning_remark']) as $cycle) {
            $roots = DB::table('activities')->where('fiscal_year', $cycle->fiscal_year)->distinct()->pluck('directorate_id')->map(fn ($id) => $rootOf((int) $id))->unique();
            foreach ($roots as $root) {
                DB::table('unit_plannings')->insert([
                    'fiscal_year' => $cycle->fiscal_year, 'directorate_id' => $root, 'planning_by' => $cycle->planning_by,
                    'planning_at' => $cycle->planning_at, 'planning_remark' => $cycle->planning_remark, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_plannings');
    }
};
