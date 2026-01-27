<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Drop temp table if it exists from a previous failed run
        Schema::dropIfExists('fiscal_envelopes_temp');

        // Update any 'Approved' status to 'Draft'
        DB::table('fiscal_envelopes')
            ->where('status', 'Approved')
            ->update(['status' => 'Draft']);

        // For SQLite, we recreate the table
        Schema::create('fiscal_envelopes_temp', function (Blueprint $table) {
            $table->id();
            $table->integer('fiscal_year')->unique();
            $table->decimal('total_amount', 15, 2);
            $table->date('approved_date')->nullable();
            $table->string('reference_number')->nullable();
            $table->string('source_authority')->nullable();
            $table->enum('status', ['Active', 'Closed', 'Draft'])->default('Draft');
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        // Select existing data and map to new columns
        $envelopes = DB::table('fiscal_envelopes')->get();
        foreach ($envelopes as $env) {
            DB::table('fiscal_envelopes_temp')->insert([
                'id' => $env->id,
                'fiscal_year' => $env->fiscal_year,
                'total_amount' => $env->total_amount,
                'approved_date' => $env->approved_date,
                'reference_number' => $env->reference_number ?? null,
                'source_authority' => $env->source_authority ?? null,
                'status' => in_array($env->status, ['Active', 'Closed', 'Draft']) ? $env->status : 'Draft',
                'is_active' => (bool) ($env->is_active ?? false),
                'created_at' => $env->created_at,
                'updated_at' => $env->updated_at,
            ]);
        }

        Schema::dropIfExists('fiscal_envelopes');
        Schema::rename('fiscal_envelopes_temp', 'fiscal_envelopes');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fiscal_envelopes_temp');

        Schema::create('fiscal_envelopes_temp', function (Blueprint $table) {
            $table->id();
            $table->integer('fiscal_year')->unique();
            $table->decimal('total_amount', 15, 2);
            $table->date('approved_date')->nullable();
            $table->string('reference_number')->nullable();
            $table->string('source_authority')->nullable();
            $table->enum('status', ['Draft', 'Approved', 'Active', 'Closed'])->default('Draft');
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        $envelopes = DB::table('fiscal_envelopes')->get();
        foreach ($envelopes as $env) {
            DB::table('fiscal_envelopes_temp')->insert([
                'id' => $env->id,
                'fiscal_year' => $env->fiscal_year,
                'total_amount' => $env->total_amount,
                'approved_date' => $env->approved_date,
                'reference_number' => $env->reference_number ?? null,
                'source_authority' => $env->source_authority ?? null,
                'status' => in_array($env->status, ['Draft', 'Approved', 'Active', 'Closed']) ? $env->status : 'Draft',
                'is_active' => (bool) ($env->is_active ?? false),
                'created_at' => $env->created_at,
                'updated_at' => $env->updated_at,
            ]);
        }

        Schema::dropIfExists('fiscal_envelopes');
        Schema::rename('fiscal_envelopes_temp', 'fiscal_envelopes');
    }
};
