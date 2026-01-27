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
        Schema::table('economic_classifications', function (Blueprint $table) {
            $table->enum('category_type', ['Current', 'Capital'])->default('Current')->after('description');
        });

        // Update types based on codes
        DB::table('economic_classifications')->whereIn('code', ['10', '20', '30'])->update(['category_type' => 'Current']);
        DB::table('economic_classifications')->whereIn('code', ['40', '50'])->update(['category_type' => 'Capital']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('economic_classifications', function (Blueprint $table) {
            $table->dropColumn('category_type');
        });
    }
};
