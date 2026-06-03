<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ai_conversations')) {
            return;
        }

        Schema::table('ai_conversations', function (Blueprint $table) {
            if (!Schema::hasColumn('ai_conversations', 'folder_id')) {
                // Optional grouping key. The host app owns the folder taxonomy;
                // the engine only stores and filters by this identifier.
                $table->string('folder_id')->nullable()->after('user_id')->index();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('ai_conversations') || !Schema::hasColumn('ai_conversations', 'folder_id')) {
            return;
        }

        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->dropColumn('folder_id');
        });
    }
};
