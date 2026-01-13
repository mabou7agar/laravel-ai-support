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
        Schema::table('users', function (Blueprint $table) {
            // Single credit balance for all AI engines
            $table->decimal('my_credits', 10, 2)->default(0)->after('updated_at');
            $table->boolean('has_unlimited_credits')->default(false)->after('my_credits');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['my_credits', 'has_unlimited_credits']);
        });
    }
};
