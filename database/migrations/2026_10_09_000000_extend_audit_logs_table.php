<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('auditable_type')->nullable()->after('user_id');
            $table->unsignedBigInteger('auditable_id')->nullable()->after('auditable_type');
            $table->json('old_values')->nullable()->after('metadata');
            $table->json('new_values')->nullable()->after('old_values');
            $table->string('ip_address', 45)->nullable()->after('new_values');
            $table->string('user_agent', 255)->nullable()->after('ip_address');

            $table->index(['auditable_type', 'auditable_id'], 'audit_logs_auditable_idx');
            $table->index(['user_id', 'created_at'], 'audit_logs_user_date_idx');
            $table->index('action', 'audit_logs_action_idx');
            $table->index('created_at', 'audit_logs_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('audit_logs_auditable_idx');
            $table->dropIndex('audit_logs_user_date_idx');
            $table->dropIndex('audit_logs_action_idx');
            $table->dropIndex('audit_logs_created_idx');
            $table->dropColumn(['auditable_type', 'auditable_id', 'old_values', 'new_values', 'ip_address', 'user_agent']);
        });
    }
};
