<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remembers exactly which records a sample dataset created, so an administrator
 * can purge them later without ever touching real data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sample_records', function (Blueprint $table) {
            $table->id();
            $table->string('dataset', 50);
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->timestamps();

            $table->unique(['model_type', 'model_id']);
            $table->index('dataset');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sample_records');
    }
};
