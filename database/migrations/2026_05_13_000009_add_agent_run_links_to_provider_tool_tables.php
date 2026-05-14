<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_provider_tool_runs')) {
            Schema::table('ai_provider_tool_runs', function (Blueprint $table) {
                if (!Schema::hasColumn('ai_provider_tool_runs', 'agent_run_id')) {
                    $table->foreignId('agent_run_id')->nullable()->index();
                }

                if (!Schema::hasColumn('ai_provider_tool_runs', 'agent_run_step_id')) {
                    $table->foreignId('agent_run_step_id')->nullable()->index();
                }
            });
        }

        foreach (['ai_provider_tool_approvals', 'ai_provider_tool_artifacts', 'ai_provider_tool_audit_events'] as $tableName) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (!Schema::hasColumn($tableName, 'agent_run_step_id')) {
                    $table->foreignId('agent_run_step_id')->nullable()->index();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['ai_provider_tool_audit_events', 'ai_provider_tool_artifacts', 'ai_provider_tool_approvals'] as $tableName) {
            if (!Schema::hasTable($tableName) || !Schema::hasColumn($tableName, 'agent_run_step_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn('agent_run_step_id');
            });
        }

        if (Schema::hasTable('ai_provider_tool_runs')) {
            Schema::table('ai_provider_tool_runs', function (Blueprint $table) {
                if (Schema::hasColumn('ai_provider_tool_runs', 'agent_run_step_id')) {
                    $table->dropColumn('agent_run_step_id');
                }

                if (Schema::hasColumn('ai_provider_tool_runs', 'agent_run_id')) {
                    $table->dropColumn('agent_run_id');
                }
            });
        }
    }
};
