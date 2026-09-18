<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meli_account_user_accesses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('meli_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('can_claim_actions')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['meli_account_id', 'user_id']);
            $table->index(['user_id', 'active', 'can_claim_actions'], 'meli_account_user_access_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meli_account_user_accesses');
    }
};
