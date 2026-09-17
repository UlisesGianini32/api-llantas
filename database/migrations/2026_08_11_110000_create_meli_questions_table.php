<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meli_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meli_account_id')->constrained('meli_accounts')->cascadeOnDelete();
            $table->string('question_id', 64);
            $table->string('item_id', 40)->index();
            $table->string('seller_id', 40)->index();
            $table->string('buyer_id', 40)->nullable()->index();
            $table->string('status', 40)->index();
            $table->text('text')->nullable();
            $table->text('answer_text')->nullable();
            $table->string('answer_status', 40)->nullable();
            $table->timestamp('question_created_at')->nullable()->index();
            $table->timestamp('answered_at')->nullable();
            $table->boolean('deleted_from_listing')->default(false);
            $table->boolean('hold')->default(false);
            $table->boolean('suspected_spam')->default(false);
            $table->string('item_title')->nullable();
            $table->string('item_thumbnail', 1000)->nullable();
            $table->string('item_permalink', 1000)->nullable();
            $table->decimal('item_price', 14, 2)->nullable();
            $table->string('currency_id', 10)->nullable();
            $table->string('sku')->nullable()->index();
            $table->unsignedInteger('available_quantity')->nullable();
            $table->json('raw')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['meli_account_id', 'question_id'], 'meli_questions_account_question_unique');
            $table->index(
                ['user_id', 'meli_account_id', 'status', 'question_created_at'],
                'meli_questions_inbox_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meli_questions');
    }
};
