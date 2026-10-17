<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * An activity code is unique within a unit and fiscal year (rows without a code are not affected). The same official
     * budget-book code may appear in different units, so the unit is part of the key. Existing data is never
     * changed here: if two activities share a code the migration stops and says which, so they can be renumbered first.
     */
    public function up(): void
    {
        $duplicates = DB::table('activities')->whereNotNull('code')->groupBy('fiscal_year', 'directorate_id', 'code')->havingRaw('COUNT(*) > 1')
            ->selectRaw('fiscal_year, directorate_id, code, COUNT(*) AS n')->get();

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException('Activity codes used more than once; renumber them, then migrate again: '
                .$duplicates->map(fn ($d) => "{$d->code} (unit {$d->directorate_id}, {$d->fiscal_year}) x{$d->n}")->implode(', '));
        }

        Schema::table('activities', function (Blueprint $table) {
            $table->unique(['fiscal_year', 'directorate_id', 'code'], 'activities_unit_year_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropUnique('activities_unit_year_code_unique');
        });
    }
};
