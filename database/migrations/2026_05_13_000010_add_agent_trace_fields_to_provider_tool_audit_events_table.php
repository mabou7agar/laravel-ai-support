<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ai_provider_tool_audit_events')) {
            return;
        }

        Schema::table('ai_provider_tool_audit_events', function (Blueprint $table) {
            if (!Schema::hasColumn('ai_provider_tool_audit_events', 'agent_run_id')) {
                $table->foreignId('agent_run_id')->nullable()->index();
            }

            if (!Schema::hasColumn('ai_provider_tool_audit_events', 'runtime')) {
                $table->string('runtime')->nullable()->index();
            }

            if (!Schema::hasColumn('ai_provider_tool_audit_events', 'decision_source')) {
                $table->string('decision_source')->nullable()->index();
            }

            if (!Schema::hasColumn('ai_provider_tool_audit_events', 'trace_id')) {
                $table->string('trace_id')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('ai_provider_tool_audit_events')) {
            return;
        }

        Schema::table('ai_provider_tool_audit_events', function (Blueprint $table) {
            foreach (['trace_id', 'decision_source', 'runtime', 'agent_run_id'] as $column) {
                if (Schema::hasColumn('ai_provider_tool_audit_events', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
