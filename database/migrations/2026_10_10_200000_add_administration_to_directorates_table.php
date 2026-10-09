<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('directorates', function (Blueprint $table) {
            // direct = a service of the ministry; indirect = a public body with its own legal
            // personality and administrative and financial autonomy (the ministry only supervises it)
            $table->string('administration', 10)->default('direct')->after('type');
            $table->string('legal_form', 20)->nullable()->after('administration');   // I.P. (public institute), E.P. (public enterprise)
            $table->string('legal_basis')->nullable()->after('legal_form');           // the diploma that creates it
        });
    }

    public function down(): void
    {
        Schema::table('directorates', function (Blueprint $table) {
            $table->dropColumn(['administration', 'legal_form', 'legal_basis']);
        });
    }
};
