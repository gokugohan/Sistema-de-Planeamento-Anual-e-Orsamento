<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where the money comes from is a property of the budget line, chosen from a short list kept by the
     * administration (State Budget, a development partner, own revenue, ...), not text typed on an indicator.
     * Existing lines take the source their activity's indicators named, otherwise the default (State Budget).
     */
    public function up(): void
    {
        Schema::create('funding_sources', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 100);
            $table->string('kind', 10)->default('Other'); // State | Partner | Other
            $table->boolean('is_default')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        $now = now();
        DB::table('funding_sources')->insert([
            ['code' => 'OGE', 'name' => 'State Budget', 'kind' => 'State', 'is_default' => true, 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'PARTNER', 'name' => 'Development partner', 'kind' => 'Partner', 'is_default' => false, 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'OWN', 'name' => 'Own revenue', 'kind' => 'Other', 'is_default' => false, 'active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
        $ids = DB::table('funding_sources')->pluck('id', 'code');

        Schema::table('line_items', function (Blueprint $table) {
            $table->foreignId('funding_source_id')->nullable()->after('economic_classification_id')->constrained('funding_sources')->nullOnDelete();
        });

        DB::table('line_items')->update(['funding_source_id' => $ids['OGE']]);

        // what the indicators of an activity said about funding decides its lines' source
        foreach (DB::table('indicators')->whereNotNull('funding_source')->select('activity_id', 'funding_source')->get()->groupBy('activity_id') as $activityId => $rows) {
            $text = mb_strtolower($rows->first()->funding_source);
            $code = match (true) {
                str_contains($text, 'parceiro') || str_contains($text, 'partner') || str_contains($text, 'parseiru') => 'PARTNER',
                str_contains($text, 'própri') || str_contains($text, 'proprio') || str_contains($text, 'own') => 'OWN',
                default => 'OGE',
            };
            DB::table('line_items')->where('activity_id', $activityId)->update(['funding_source_id' => $ids[$code]]);
        }
    }

    public function down(): void
    {
        Schema::table('line_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('funding_source_id');
        });
        Schema::dropIfExists('funding_sources');
    }
};
