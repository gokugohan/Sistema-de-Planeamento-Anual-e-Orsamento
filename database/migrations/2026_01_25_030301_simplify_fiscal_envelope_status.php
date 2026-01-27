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
        // For SQLite, we need to recreate the table to change enum values
        Schema::dropIfExists('fiscal_envelopes_v4_temp');

        // Create temp table with new enum values
        Schema::create('fiscal_envelopes_v4_temp', function (Blueprint $table) {
            $table->id();
            $table->integer('fiscal_year')->unique();
            $table->decimal('total_amount', 15, 2);
            $table->date('approved_date')->nullable();
            $table->string('reference_number')->nullable();
            $table->string('source_authority')->nullable();
            $table->enum('status', ['Draft', 'Pending Approval', 'Approved', 'Closed'])->default('Draft');
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();
            $table->timestamps();
        });

        // Copy data from old table, mapping "Active" -> "Approved"
        $envelopes = DB::table('fiscal_envelopes')->get();
        foreach ($envelopes as $env) {
            $status = $env->status;
            if ($status === 'Active') {
                $status = 'Approved';
            }

            DB::table('fiscal_envelopes_v4_temp')->insert([
                'id' => $env->id,
                'fiscal_year' => $env->fiscal_year,
                'total_amount' => $env->total_amount,
                'approved_date' => $env->approved_date,
                'reference_number' => $env->reference_number ?? null,
                'source_authority' => $env->source_authority ?? null,
                'status' => $status,
                'start_date' => $env->start_date ?? null,
                'due_date' => $env->due_date ?? null,
                'created_at' => $env->created_at,
                'updated_at' => $env->updated_at,
            ]);
        }

        // Drop old table and rename temp
        Schema::dropIfExists('fiscal_envelopes');
        Schema::rename('fiscal_envelopes_v4_temp', 'fiscal_envelopes');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Reverse: Map "Approved" back to "Active"
        Schema::dropIfExists('fiscal_envelopes_v4_temp');

        Schema::create('fiscal_envelopes_v4_temp', function (Blueprint $table) {
            $table->id();
            $table->integer('fiscal_year')->unique();
            $table->decimal('total_amount', 15, 2);
            $table->date('approved_date')->nullable();
            $table->string('reference_number')->nullable();
            $table->string('source_authority')->nullable();
            $table->enum('status', ['Active', 'Closed', 'Draft'])->default('Draft');
            $table->boolean('is_active')->default(false);
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();
            $table->timestamps();
        });

        $envelopes = DB::table('fiscal_envelopes')->get();
        foreach ($envelopes as $env) {
            $status = $env->status;
            if ($status === 'Approved' || $status === 'Pending Approval') {
                $status = 'Active';
            }

            DB::table('fiscal_envelopes_v4_temp')->insert([
                'id' => $env->id,
                'fiscal_year' => $env->fiscal_year,
                'total_amount' => $env->total_amount,
                'approved_date' => $env->approved_date,
                'reference_number' => $env->reference_number ?? null,
                'source_authority' => $env->source_authority ?? null,
                'status' => $status,
                'is_active' => false,
                'start_date' => $env->start_date ?? null,
                'due_date' => $env->due_date ?? null,
                'created_at' => $env->created_at,
                'updated_at' => $env->updated_at,
            ]);
        }

        Schema::dropIfExists('fiscal_envelopes');
        Schema::rename('fiscal_envelopes_v4_temp', 'fiscal_envelopes');
    }
};
