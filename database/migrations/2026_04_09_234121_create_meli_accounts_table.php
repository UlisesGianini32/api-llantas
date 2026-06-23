<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meli_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('meli_user_id')->index();
            $table->string('nickname')->nullable();
            $table->unsignedBigInteger('official_store_id')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'meli_user_id']);
        });

        if (! Schema::hasTable('users')) {
            return;
        }

        $hasOfficialStore = Schema::hasColumn('users', 'official_store_id');

        $users = DB::table('users')
            ->whereNotNull('meli_id')
            ->where('meli_id', '!=', '')
            ->get();

        foreach ($users as $u) {
            $mid = trim((string) $u->meli_id);
            if ($mid === '') {
                continue;
            }

            $exists = DB::table('meli_accounts')
                ->where('user_id', $u->id)
                ->where('meli_user_id', $mid)
                ->exists();
            if ($exists) {
                continue;
            }

            $official = $hasOfficialStore ? ($u->official_store_id ?? null) : null;

            DB::table('meli_accounts')->insert([
                'user_id' => $u->id,
                'meli_user_id' => $mid,
                'nickname' => null,
                'official_store_id' => $official,
                'access_token' => $u->access_token,
                'refresh_token' => $u->refresh_token,
                'expires_at' => $u->expires_at,
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('meli_accounts');
    }
};
