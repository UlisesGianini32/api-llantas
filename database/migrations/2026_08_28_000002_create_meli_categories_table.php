<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('meli_categories')) {
            return;
        }

        Schema::create('meli_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('category_id', 64)->unique();
            $table->string('name');
            $table->string('parent_id', 64)->nullable()->index();
            $table->string('root_category_id', 64)->nullable()->index();
            $table->json('path_from_root')->nullable();
            $table->timestamp('last_synced_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // The table may have existed before this defensive migration. Without ownership
        // metadata, rollback cannot safely distinguish it from a table created by up().
    }
};
