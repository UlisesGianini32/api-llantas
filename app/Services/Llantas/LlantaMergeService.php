<?php

namespace App\Services\Llantas;

use App\Models\Llanta;
use App\Models\LlantaSkuAlias;
use App\Models\ProductoCompuesto;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LlantaMergeService
{
    public function mergeBySku(string $oldSku, string $newSku, bool $execute = false): array
    {
        $old = Llanta::where('sku', $oldSku)->firstOrFail();
        $new = Llanta::where('sku', $newSku)->firstOrFail();

        $plan = [
            'old_id' => $old->id,
            'new_id' => $new->id,
            'old_sku' => $oldSku,
            'new_sku' => $newSku,
            'canonical_id' => $new->id,
            'delete_id' => $old->id,
            'compuestos_old' => $old->compuestos()->pluck('sku')->all(),
            'compuestos_new' => $new->compuestos()->pluck('sku')->all(),
        ];

        if (!$execute) {
            return $plan + ['executed' => false];
        }

        DB::transaction(function () use ($old, $new, $oldSku, $newSku) {
            $old->lockForUpdate();
            $new->lockForUpdate();

            LlantaSkuAlias::firstOrCreate(
                ['sku_alias' => $oldSku],
                ['llanta_id' => $new->id, 'source' => 'merge']
            );

            foreach ($old->compuestos()->get() as $oldCompuesto) {
                $suffix = $oldCompuesto->tipo === 'par' ? '-2' : '-4';
                $targetSku = $newSku . $suffix;
                $newCompuesto = ProductoCompuesto::where('sku', $targetSku)->first();

                if ($newCompuesto) {
                    $this->preserveBestData($newCompuesto, $oldCompuesto);
                    $oldCompuesto->delete();
                } else {
                    $oldCompuesto->update([
                        'llanta_id' => $new->id,
                        'sku' => $targetSku,
                    ]);
                }
            }

            $this->preserveBestData($new, $old);

            DB::table('meli_publications')
                ->where('sku', $oldSku)
                ->update(['sku' => $newSku, 'updated_at' => now()]);

            DB::table('meli_publications')
                ->where('sku', $oldSku . '-2')
                ->update(['sku' => $newSku . '-2', 'updated_at' => now()]);

            DB::table('meli_publications')
                ->where('sku', $oldSku . '-4')
                ->update(['sku' => $newSku . '-4', 'updated_at' => now()]);

            LlantaSkuAlias::where('llanta_id', $old->id)->update(['llanta_id' => $new->id]);
            $old->delete();
        });

        return $plan + ['executed' => true];
    }

    private function preserveBestData($target, $source): void
    {
        $updates = [];

        foreach (['MLM', 'title_familyname', 'marca', 'medida'] as $field) {
            if (blank($target->{$field} ?? null) || in_array($target->{$field} ?? null, ['GENERICA', 'N/A'], true)) {
                if (filled($source->{$field} ?? null) && !in_array($source->{$field}, ['GENERICA', 'N/A'], true)) {
                    $updates[$field] = $source->{$field};
                }
            }
        }

        if (($source->price_mode ?? 'auto') === 'manual' && ($target->price_mode ?? 'auto') !== 'manual') {
            $updates['price_mode'] = 'manual';
            $updates['precio_ML'] = $source->precio_ML;
            if (isset($source->price_locked_at)) {
                $updates['price_locked_at'] = $source->price_locked_at;
            }
        }

        if ($updates) {
            $target->update($updates);
        }
    }
}
