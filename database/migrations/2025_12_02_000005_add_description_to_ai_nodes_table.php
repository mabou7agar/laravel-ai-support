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
        Schema::table('ai_nodes', function (Blueprint $table) {
            $table->text('description')->nullable()->after('url');
            $table->json('domains')->nullable()->after('capabilities'); // ['ecommerce', 'blog', 'crm']
            $table->json('data_types')->nullable()->after('domains'); // ['products', 'posts', 'customers']
            $table->json('keywords')->nullable()->after('data_types'); // ['shopping', 'articles', 'sales']
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_nodes', function (Blueprint $table) {
            $table->dropColumn(['description', 'domains', 'data_types', 'keywords']);
        });
    }
};
