<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('pending_actions', function (Blueprint $table) {
            $table->json('ai_config')->nullable()->after('node_slug');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pending_actions', function (Blueprint $table) {
            $table->dropColumn('ai_config');
        });
    }
};
