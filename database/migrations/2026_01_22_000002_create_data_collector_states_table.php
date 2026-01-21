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
        Schema::create('data_collector_states', function (Blueprint $table) {
            $table->id();
            $table->string('session_id')->unique();
            $table->longText('state_data'); // JSON state data
            $table->string('status')->default('collecting');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            
            $table->index('session_id');
            $table->index('status');
            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('data_collector_states');
    }
};
