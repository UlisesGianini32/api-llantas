<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'syscom_meli_category_audits',
            function (Blueprint $table) {
                $table->id();

                $table->foreignId(
                    'syscom_category_id'
                )
                    ->unique()
                    ->constrained(
                        'syscom_categories'
                    )
                    ->cascadeOnDelete();

                $table->unsignedInteger(
                    'sample_count'
                )->default(0);

                $table->unsignedInteger(
                    'valid_predictions'
                )->default(0);

                $table->string(
                    'dominant_meli_category_id',
                    32
                )->nullable();

                $table->string(
                    'dominant_meli_category_name'
                )->nullable();

                $table->string(
                    'dominant_meli_category_path',
                    1000
                )->nullable();

                $table->decimal(
                    'consensus',
                    5,
                    2
                )->default(0);

                $table->decimal(
                    'coverage',
                    5,
                    2
                )->default(0);

                $table->unsignedTinyInteger(
                    'score'
                )->default(0);

                $table->string(
                    'status',
                    32
                );

                $table->json(
                    'distribution'
                )->nullable();

                $table->json(
                    'errors'
                )->nullable();

                $table->timestamp(
                    'audited_at'
                )->nullable();

                $table->timestamps();

                $table->index('status');
                $table->index('score');
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'syscom_meli_category_audits'
        );
    }
};
