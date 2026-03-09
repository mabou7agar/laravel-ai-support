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

        $hasCredits = Schema::hasColumn('users', 'my_credits');
        $hasUnlimited = Schema::hasColumn('users', 'has_unlimited_credits');

        if ($hasCredits && $hasUnlimited) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            // Single credit balance for all AI engines
            if (!Schema::hasColumn('users', 'my_credits')) {
                $table->decimal('my_credits', 10, 2)->default(0)->after('updated_at');
            }

            if (!Schema::hasColumn('users', 'has_unlimited_credits')) {
                $table->boolean('has_unlimited_credits')->default(false)->after('my_credits');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('users')) {
            return;
        }

        $dropColumns = [];
        if (Schema::hasColumn('users', 'my_credits')) {
            $dropColumns[] = 'my_credits';
        }
        if (Schema::hasColumn('users', 'has_unlimited_credits')) {
            $dropColumns[] = 'has_unlimited_credits';
        }

        if ($dropColumns === []) {
            return;
        }

        Schema::table('users', function (Blueprint $table) use ($dropColumns) {
            $table->dropColumn($dropColumns);
        });
    }
};
