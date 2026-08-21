<?php

use App\Models\MeliAccount;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('meli_publications', function (Blueprint $table) {
            $table->foreignId('meli_account_id')
                ->nullable()
                ->after('user_id')
                ->constrained('meli_accounts')
                ->nullOnDelete();
            $table->string('source_mlm')->nullable()->after('mlm')->index();
            $table->index(['user_id', 'meli_account_id', 'sku'], 'meli_pub_user_account_sku_idx');
        });

        $accountsByUser = MeliAccount::query()
            ->get(['id', 'user_id', 'meli_user_id', 'is_default'])
            ->groupBy('user_id');

        DB::table('meli_publications')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($accountsByUser) {
                foreach ($rows as $row) {
                    $raw = json_decode((string) ($row->raw ?? ''), true);
                    $raw = is_array($raw) ? $raw : [];
                    $item = isset($raw['item']) && is_array($raw['item']) ? $raw['item'] : $raw;

                    $sourceMlm = trim((string) ($raw['copied_from_mlm'] ?? '')) ?: null;
                    $meliUserId = trim((string) (
                        $raw['published_to_meli_user_id']
                        ?? $item['seller_id']
                        ?? $raw['seller_id']
                        ?? ''
                    ));

                    $accounts = $accountsByUser->get($row->user_id, collect());
                    $account = $meliUserId !== ''
                        ? $accounts->first(fn ($candidate) => (string) $candidate->meli_user_id === $meliUserId)
                        : null;

                    if (! $account && $sourceMlm === null) {
                        $account = $accounts->firstWhere('is_default', true) ?: $accounts->first();
                    }

                    DB::table('meli_publications')
                        ->where('id', $row->id)
                        ->update([
                            'meli_account_id' => $account?->id,
                            'source_mlm' => $sourceMlm,
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('meli_publications', function (Blueprint $table) {
            $table->dropIndex('meli_pub_user_account_sku_idx');
            $table->dropForeign(['meli_account_id']);
            $table->dropColumn(['meli_account_id', 'source_mlm']);
        });
    }
};
