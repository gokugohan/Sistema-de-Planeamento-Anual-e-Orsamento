<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spending is reported every month (the government's budget execution reports are monthly); indicators
     * stay quarterly. A row is now one budget line in one month; its quarter is kept (derived from the month)
     * so that the quarterly reports and indexes keep working.
     *
     * Entries made before this change were for a whole quarter: they are filed under the quarter's last month.
     */
    public function up(): void
    {
        Schema::table('budget_executions', function (Blueprint $table) {
            $table->unsignedTinyInteger('month')->nullable()->after('quarter');
        });

        DB::table('budget_executions')->update(['month' => DB::raw('quarter * 3')]);

        Schema::table('budget_executions', function (Blueprint $table) {
            $table->unsignedTinyInteger('month')->nullable(false)->change();
            // the new unique goes first: the line_item_id foreign key needs an index at all times
            $table->unique(['line_item_id', 'month']);
            $table->dropUnique(['line_item_id', 'quarter']);
            $table->index(['month', 'status']);
        });
    }

    /** Back to quarters: the months of a quarter are added up into one entry (the worst status wins). */
    public function down(): void
    {
        $rank = ['Verified' => 0, 'Submitted' => 1, 'Draft' => 2, 'Rejected' => 3];
        $groups = DB::table('budget_executions')->orderBy('line_item_id')->orderBy('quarter')->get()->groupBy(fn ($r) => $r->line_item_id.'-'.$r->quarter);
        foreach ($groups as $rows) {
            if ($rows->count() === 1) {
                continue;
            }
            $keep = $rows->sortByDesc('month')->first();
            $worst = $rows->sortByDesc(fn ($r) => $rank[$r->status] ?? 2)->first();
            DB::table('budget_executions')->where('id', $keep->id)->update([
                'committed' => $rows->sum('committed'), 'paid' => $rows->sum('paid'), 'status' => $worst->status,
            ]);
            DB::table('budget_executions')->whereIn('id', $rows->pluck('id')->reject(fn ($id) => $id === $keep->id))->delete();
        }

        Schema::table('budget_executions', function (Blueprint $table) {
            $table->dropIndex(['month', 'status']);
            $table->unique(['line_item_id', 'quarter']);
            $table->dropUnique(['line_item_id', 'month']);
            $table->dropColumn('month');
        });
    }
};
