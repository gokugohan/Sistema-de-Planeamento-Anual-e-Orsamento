<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Programmes belong to a fiscal year: they can differ from one year to the next, so the same OGE code
     * is registered once per year. Existing programmes are split by the years their activities use: the
     * first year keeps the programme, every other year gets a copy and its activities are pointed at it.
     * A programme no activity uses goes to the current year.
     */
    public function up(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            $table->unsignedSmallInteger('fiscal_year')->nullable()->after('id');
            $table->dropUnique(['oge_code']);
        });

        $hasRegistry = Schema::hasTable('sample_records');

        foreach (DB::table('programs')->orderBy('id')->get() as $program) {
            $years = DB::table('activities')->where('program_id', $program->id)->distinct()->orderBy('fiscal_year')->pluck('fiscal_year')->map(fn ($y) => (int) $y)->all();

            if (! $years) {
                DB::table('programs')->where('id', $program->id)->update(['fiscal_year' => (int) date('Y')]);

                continue;
            }

            DB::table('programs')->where('id', $program->id)->update(['fiscal_year' => array_shift($years)]);

            foreach ($years as $year) {
                $copy = (array) $program;
                unset($copy['id']);
                $copy['fiscal_year'] = $year;
                $copyId = DB::table('programs')->insertGetId($copy);

                DB::table('activities')->where('program_id', $program->id)->where('fiscal_year', $year)->update(['program_id' => $copyId]);

                // a copy of a sample programme is a sample programme too, so purging the sample removes it
                if ($hasRegistry && ($record = DB::table('sample_records')->where('model_type', \App\Models\Program::class)->where('model_id', $program->id)->first())) {
                    DB::table('sample_records')->insert(['dataset' => $record->dataset, 'model_type' => $record->model_type, 'model_id' => $copyId, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        }

        Schema::table('programs', function (Blueprint $table) {
            $table->unsignedSmallInteger('fiscal_year')->nullable(false)->change();
            $table->unique(['fiscal_year', 'oge_code']);
        });
    }

    /** Back to one programme per code: the copies are merged into the oldest one. */
    public function down(): void
    {
        $keep = [];
        foreach (DB::table('programs')->orderBy('id')->get() as $program) {
            if (! isset($keep[$program->oge_code])) {
                $keep[$program->oge_code] = $program->id;

                continue;
            }
            DB::table('activities')->where('program_id', $program->id)->update(['program_id' => $keep[$program->oge_code]]);
            if (Schema::hasTable('sample_records')) {
                DB::table('sample_records')->where('model_type', \App\Models\Program::class)->where('model_id', $program->id)->delete();
            }
            DB::table('programs')->where('id', $program->id)->delete();
        }

        Schema::table('programs', function (Blueprint $table) {
            $table->dropUnique(['fiscal_year', 'oge_code']);
            $table->dropColumn('fiscal_year');
        });
        Schema::table('programs', function (Blueprint $table) {
            $table->unique('oge_code');
        });
    }
};
