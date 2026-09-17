<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meli_claim_attachment_uploads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('meli_claim_id')->constrained('meli_claims')->cascadeOnDelete();
            $table->foreignId('meli_account_id')->constrained('meli_accounts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('original_filename'); $table->string('safe_filename', 125);
            $table->string('file_hash', 64)->index(); $table->string('mime_type', 80); $table->unsignedBigInteger('size_bytes');
            $table->string('remote_filename')->nullable(); $table->unsignedSmallInteger('remote_status')->nullable();
            $table->boolean('success')->nullable(); $table->string('error_code', 80)->nullable(); $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('meli_claim_attachment_uploads'); }
};
