<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ai_provider_tool_approvals')) {
            return;
        }

        Schema::table('ai_provider_tool_approvals', function (Blueprint $table) {
            if (!Schema::hasColumn('ai_provider_tool_approvals', 'expires_at')) {
                $table->timestamp('expires_at')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('ai_provider_tool_approvals') || !Schema::hasColumn('ai_provider_tool_approvals', 'expires_at')) {
            return;
        }

        Schema::table('ai_provider_tool_approvals', function (Blueprint $table) {
            $table->dropColumn('expires_at');
        });
    }
};
