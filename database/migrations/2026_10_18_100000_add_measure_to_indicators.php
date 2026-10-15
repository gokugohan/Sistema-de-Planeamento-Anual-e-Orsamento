<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How an indicator is measured over the year. A count (reports published, processes digitized) is
     * CUMULATIVE: each quarter adds to the total. A rate or a state (a percentage reached, availability)
     * is a LEVEL: the latest quarter's value is where the indicator stands; adding the quarters up would
     * overstate it. Indicators measured in percent are levels by default.
     */
    public function up(): void
    {
        Schema::table('indicators', function (Blueprint $table) {
            $table->string('measure', 12)->default('cumulative')->after('unit');
        });

        DB::table('indicators')->where('unit', '%')->update(['measure' => 'level']);
    }

    public function down(): void
    {
        Schema::table('indicators', function (Blueprint $table) {
            $table->dropColumn('measure');
        });
    }
};
