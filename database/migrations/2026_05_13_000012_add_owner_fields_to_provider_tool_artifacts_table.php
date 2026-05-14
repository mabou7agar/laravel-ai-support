<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ai_provider_tool_artifacts')) {
            return;
        }

        Schema::table('ai_provider_tool_artifacts', function (Blueprint $table) {
            if (!Schema::hasColumn('ai_provider_tool_artifacts', 'owner_type')) {
                $table->string('owner_type')->nullable()->index();
            }

            if (!Schema::hasColumn('ai_provider_tool_artifacts', 'owner_id')) {
                $table->string('owner_id')->nullable()->index();
            }

            if (!Schema::hasColumn('ai_provider_tool_artifacts', 'source')) {
                $table->string('source')->nullable()->index();
            }

            if (!Schema::hasColumn('ai_provider_tool_artifacts', 'expires_at')) {
                $table->timestamp('expires_at')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('ai_provider_tool_artifacts')) {
            return;
        }

        Schema::table('ai_provider_tool_artifacts', function (Blueprint $table) {
            foreach (['expires_at', 'source', 'owner_id', 'owner_type'] as $column) {
                if (Schema::hasColumn('ai_provider_tool_artifacts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
