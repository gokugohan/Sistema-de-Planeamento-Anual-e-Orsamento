<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('fiscal_envelopes', function (Blueprint $table) {
            $table->string('reference_number')->nullable()->after('fiscal_year');
            $table->string('source_authority')->nullable()->after('reference_number');
            $table->string('status')->default('Draft')->after('total_amount');
            $table->json('category_allocations')->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fiscal_envelopes', function (Blueprint $table) {
            $table->dropColumn(['reference_number', 'source_authority', 'status', 'category_allocations']);
        });
    }
};
