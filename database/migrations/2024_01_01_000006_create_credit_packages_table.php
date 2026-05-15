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
        Schema::create('credit_packages', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type'); // Tenant, Workspace, User
            $table->unsignedBigInteger('owner_id');
            $table->decimal('amount', 10, 2); // Original amount
            $table->decimal('balance', 10, 2); // Remaining balance
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->string('source')->default('manual'); // manual, purchase, bonus, refund
            $table->timestamps();
            
            $table->index(['owner_type', 'owner_id']);
            $table->index('expires_at');
            $table->index(['owner_type', 'owner_id', 'balance']);
        });

        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type');
            $table->unsignedBigInteger('owner_id');
            $table->unsignedBigInteger('package_id')->nullable();
            $table->decimal('amount', 10, 2); // Positive for addition, negative for deduction
            $table->enum('type', ['addition', 'deduction', 'expiration', 'refund']);
            $table->string('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');
            
            $table->index(['owner_type', 'owner_id']);
            $table->index('package_id');
            $table->index('created_at');
            
            $table->foreign('package_id')
                ->references('id')
                ->on('credit_packages')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
        Schema::dropIfExists('credit_packages');
    }
};
