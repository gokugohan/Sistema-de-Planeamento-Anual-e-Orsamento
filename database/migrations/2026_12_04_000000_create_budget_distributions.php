<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The distribution of the institution's budget among its directorates (an optional rule, PlanOptions::budgetDistribution).
 *
 * The law (Lei n.º 3/2025 and Decreto-Lei n.º 42/2025, art. 18-19) sets the total of each budget title, that is of the
 * institution; how that total is shared among the institution's own directorates is an internal act, usually a
 * despacho of the head of the institution prepared by its finance and administration directorate. A distribution
 * splits one budget decision by directorate and economic category; what is not given to a directorate stays with
 * the institution (the reserve, directorate_id NULL), salaries included where they are managed centrally.
 *
 * Also: the ceiling details of the budget circular on a decision (art. 19.2 f and g), which funding sources belong to
 * the institution's own budget title, and the effect on the plan's targets that a revision moving money states
 * (art. 35.9).
 */
return new class extends Migration
{
    public function up(): void
    {
        // each step checks whether it is done: MySQL cannot undo a change of the schema, so a step that failed half-way
        // must be able to run again
        if (! Schema::hasColumn('budget_decisions', 'committed_amount')) {
            Schema::table('budget_decisions', function (Blueprint $table) {
                $table->decimal('committed_amount', 16, 2)->nullable()->after('total');
                $table->decimal('new_policy_space', 16, 2)->nullable()->after('committed_amount');
            });
        }

        if (! Schema::hasTable('budget_distributions')) {
            Schema::create('budget_distributions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('budget_decision_id')->constrained('budget_decisions')->cascadeOnDelete();
                $table->unsignedSmallInteger('fiscal_year');
                $table->unsignedInteger('version');
                $table->string('status', 12)->default('draft');
                $table->string('act_reference', 255)->nullable();
                $table->date('act_date')->nullable();
                $table->text('note')->nullable();
                $table->text('return_note')->nullable();
                $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('submitted_at')->nullable();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->boolean('on_behalf')->default(false);
                $table->timestamps();

                $table->unique(['fiscal_year', 'version']);
                $table->index(['fiscal_year', 'status']);
            });
        }

        if (! Schema::hasTable('budget_distribution_lines')) {
            Schema::create('budget_distribution_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('budget_distribution_id')->constrained('budget_distributions')->cascadeOnDelete();
                // no foreign key: a distribution is a record of what was decided and must survive a later change of the structure
                $table->unsignedBigInteger('directorate_id')->nullable()->index();
                $table->foreignId('economic_classification_id')->constrained('economic_classifications');
                $table->decimal('amount', 16, 2);
                $table->timestamps();

                $table->index(['budget_distribution_id', 'directorate_id'], 'bdl_distribution_unit_index');
            });
        }

        if (! Schema::hasColumn('funding_sources', 'counts_in_title')) {
            Schema::table('funding_sources', function (Blueprint $table) {
                $table->boolean('counts_in_title')->default(false)->after('kind');
            });
        }
        // the State Budget's own sources belong to the institution's title; partners and own revenue do not
        DB::table('funding_sources')->where('kind', 'State')->update(['counts_in_title' => true]);

        if (! Schema::hasColumn('activities', 'revision_effect')) {
            Schema::table('activities', function (Blueprint $table) {
                $table->text('revision_effect')->nullable()->after('revision_reason');
            });
        }
    }

    public function down(): void
    {
        Schema::table('activities', fn (Blueprint $table) => $table->dropColumn('revision_effect'));
        Schema::table('funding_sources', fn (Blueprint $table) => $table->dropColumn('counts_in_title'));
        Schema::dropIfExists('budget_distribution_lines');
        Schema::dropIfExists('budget_distributions');
        Schema::table('budget_decisions', function (Blueprint $table) {
            $table->dropColumn(['committed_amount', 'new_policy_space']);
        });
    }
};
