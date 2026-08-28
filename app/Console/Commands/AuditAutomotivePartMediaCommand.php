<?php

namespace App\Console\Commands;

use App\Models\AutomotivePartMedia;
use App\Services\Autopartes\MediaPricing\AutomotivePartMediaPricingConfiguration;
use App\Services\Autopartes\MediaPricing\AutomotivePartMediaPricingLocalOnlyGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class AuditAutomotivePartMediaCommand extends Command
{
    protected $signature = 'autopartes:media-audit {--part-id=} {--rule-id=} {--limit=} {--force} {--dry-run}';
    protected $description = 'Audita localmente los medios respaldados de Autopartes';

    public function handle(AutomotivePartMediaPricingConfiguration $configuration, AutomotivePartMediaPricingLocalOnlyGuard $guard): int
    {
        $guard->assert('audit_media');
        $limit = $this->integer('limit') ?? $configuration->maxBatch(); $partId = $this->integer('part-id'); $ruleId = $this->integer('rule-id');
        if ($limit === false || $partId === false || $ruleId === false || $limit > $configuration->maxBatch()) {
            $this->error('Los IDs deben ser positivos y el límite no puede superar el máximo configurado.'); return self::FAILURE;
        }
        if (! $this->option('dry-run') && ! $configuration->enabled()) { $this->error('La Fase 6 está deshabilitada.'); return self::FAILURE; }
        $media = AutomotivePartMedia::query()->when($partId, fn ($q) => $q->where('automotive_part_id', $partId))->orderBy('id')->limit($limit)->get();
        $rows = $media->map(function (AutomotivePartMedia $item) {
            $disk = Storage::disk($item->disk); $exists = $disk->exists($item->path); $hashMatches = false;
            if ($exists) { $stream = $disk->readStream($item->path); if (is_resource($stream)) { $hashMatches = hash_equals($item->sha256, hash('sha256', stream_get_contents($stream))); fclose($stream); } }
            return [$item->id, $item->automotive_part_id, $item->status, $item->sha256, $exists && $hashMatches ? 'ok' : ($exists ? 'hash_mismatch' : 'missing_file')];
        });
        if ($this->option('dry-run')) $this->info('Dry-run: no se persistió, movió ni encoló nada; solicitudes externas: 0.');
        $this->table(['Medio', 'Autoparte', 'Estado', 'Fingerprint SHA-256', 'Resultado'], $rows);
        return $rows->contains(fn ($row) => $row[4] !== 'ok') ? self::FAILURE : self::SUCCESS;
    }

    private function integer(string $name): int|false|null { $value = $this->option($name); if ($value === null || $value === '') return null; return filter_var($value, FILTER_VALIDATE_INT) !== false && (int) $value > 0 ? (int) $value : false; }
}
