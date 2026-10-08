<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** table => [index name => columns] — FK columns and hot filters were unindexed. */
    private array $indexes = [
        'activities' => [
            'activities_year_status_idx' => ['fiscal_year', 'status'],
            'activities_directorate_idx' => ['directorate_id'],
            'activities_program_idx' => ['program_id'],
        ],
        'line_items' => [
            'line_items_activity_idx' => ['activity_id'],
            'line_items_class_idx' => ['economic_classification_id'],
        ],
        'adjusted_budgets' => ['adjusted_budgets_line_item_idx' => ['line_item_id']],
        'indicators' => ['indicators_activity_idx' => ['activity_id']],
        'indicator_progress' => ['indicator_progress_ind_status_idx' => ['indicator_id', 'status']],
        'fiscal_envelope_category_allocations' => ['fe_cat_alloc_envelope_idx' => ['fiscal_envelope_id']],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $table => $defs) {
            Schema::table($table, function (Blueprint $t) use ($defs) {
                foreach ($defs as $name => $cols) {
                    $t->index($cols, $name);
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $table => $defs) {
            Schema::table($table, function (Blueprint $t) use ($defs) {
                foreach (array_keys($defs) as $name) {
                    $t->dropIndex($name);
                }
            });
        }
    }
};
