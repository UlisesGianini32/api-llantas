<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meli_claim_action_logs', function (Blueprint $table): void {
            $table->string('source', 20)->default('web')->after('user_id')->index();
        });

        DB::table('meli_claim_action_logs')
            ->select(['id', 'request_payload_sanitized'])
            ->whereNotNull('request_payload_sanitized')
            ->orderBy('id')
            ->chunkById(500, function ($logs): void {
                foreach ($logs as $log) {
                    $payload = is_string($log->request_payload_sanitized)
                        ? json_decode($log->request_payload_sanitized, true)
                        : $log->request_payload_sanitized;

                    if (is_array($payload) && ($payload['source'] ?? null) === 'telegram') {
                        DB::table('meli_claim_action_logs')->where('id', $log->id)->update(['source' => 'telegram']);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('meli_claim_action_logs', function (Blueprint $table): void {
            $table->dropIndex(['source']);
            $table->dropColumn('source');
        });
    }
};
