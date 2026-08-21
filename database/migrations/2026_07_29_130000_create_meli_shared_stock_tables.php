<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meli_shared_stock_groups', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('master_account_id')->index();
            $table->string('group_key', 64)->unique();
            $table->string('link_key', 512)->nullable();
            $table->string('sku', 191)->nullable()->index();
            $table->string('master_mlm', 32)->nullable()->index();
            $table->string('master_variation_id', 64)->nullable()->index();
            $table->unsignedInteger('stock')->default(0);
            $table->string('link_method', 40)->default('auto');
            $table->boolean('is_enabled')->default(true)->index();
            $table->timestamp('activated_at')->nullable()->index();
            $table->timestamp('last_pushed_at')->nullable();
            $table->timestamp('last_reconciled_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'master_account_id', 'is_enabled'], 'mssg_user_master_enabled_idx');
        });

        Schema::create('meli_shared_stock_members', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('group_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('meli_account_id')->index();
            $table->unsignedBigInteger('meli_publication_id')->nullable()->index();
            $table->string('member_key', 191)->unique();
            $table->string('mlm', 32)->index();
            $table->string('variation_id', 64)->nullable()->index();
            $table->string('sku', 191)->nullable()->index();
            $table->string('role', 20)->default('mirror')->index();
            $table->string('match_method', 40)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_fulfillment')->default(false)->index();
            $table->timestamp('last_push_at')->nullable();
            $table->string('last_push_status', 30)->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['group_id', 'is_active'], 'mssm_group_active_idx');
            $table->index(['meli_account_id', 'mlm', 'variation_id'], 'mssm_account_item_variation_idx');
        });

        Schema::create('meli_shared_stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('group_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('meli_account_id')->nullable()->index();
            $table->unsignedBigInteger('meli_order_id')->nullable()->index();
            $table->string('order_id', 64)->nullable()->index();
            $table->string('movement_key', 64)->unique();
            $table->string('type', 30)->index();
            $table->string('item_id', 32)->nullable()->index();
            $table->string('variation_id', 64)->nullable()->index();
            $table->string('sku', 191)->nullable()->index();
            $table->unsignedInteger('applied_quantity')->default(0);
            $table->integer('last_adjustment')->default(0);
            $table->string('last_status', 40)->nullable();
            $table->unsignedInteger('stock_before')->default(0);
            $table->unsignedInteger('stock_after')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['group_id', 'order_id'], 'mssmv_group_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meli_shared_stock_movements');
        Schema::dropIfExists('meli_shared_stock_members');
        Schema::dropIfExists('meli_shared_stock_groups');
    }
};
