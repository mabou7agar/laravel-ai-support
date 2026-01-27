<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_nodes', function (Blueprint $table) {
            $table->json('autonomous_collectors')->nullable()->after('workflows');
        });
    }

    public function down(): void
    {
        Schema::table('ai_nodes', function (Blueprint $table) {
            $table->dropColumn('autonomous_collectors');
        });
    }
};
