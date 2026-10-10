<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('indicators', function (Blueprint $table) {
            // Official plans often word the starting point and the target ("a) 35 500; b) 65 000"):
            // keep the words next to the numeric annual target used for monitoring.
            $table->string('baseline', 500)->nullable()->after('unit');
            $table->string('target_text', 1000)->nullable()->after('annual_target');
        });
    }

    public function down(): void
    {
        Schema::table('indicators', function (Blueprint $table) {
            $table->dropColumn(['baseline', 'target_text']);
        });
    }
};
