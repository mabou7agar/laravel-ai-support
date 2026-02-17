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
        if (!Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'my_credits')) {
                $table->decimal('my_credits', 10, 2)->default(0);
            }
            if (!Schema::hasColumn('users', 'has_unlimited_credits')) {
                $table->boolean('has_unlimited_credits')->default(false);
            }
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
