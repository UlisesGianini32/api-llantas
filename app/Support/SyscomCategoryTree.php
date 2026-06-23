<?php

namespace App\Support;

use App\Services\SyscomApiService;

/**
 * Recorre subcategorías SYSCOM (GET /categorias/{id}) para armar IDs usables en /productos?categoria=.
 */
class SyscomCategoryTree
{
    /**
     * @param  array<int|string>  $rootIds
     * @return array<int, string>
     */
    public static function collectIds(
        SyscomApiService $api,
        string $token,
        array $rootIds,
        int $maxIds = 400
    ): array {
        $maxIds = max(1, $maxIds);
        $queue = [];
        $seen = [];
        $collected = [];

        foreach ($rootIds as $rootId) {
            $id = trim((string) $rootId);
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $queue[] = $id;
        }

        while ($queue !== [] && count($collected) < $maxIds) {
            $id = array_shift($queue);
            if ($id === null || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $collected[] = $id;

            try {
                $cat = $api->getCategory($token, $id);
            } catch (\Throwable) {
                continue;
            }

            $subs = $cat['subcategorías'] ?? $cat['subcategorias'] ?? [];
            if (! is_array($subs)) {
                continue;
            }

            foreach ($subs as $sub) {
                if (! is_array($sub)) {
                    continue;
                }
                $subId = trim((string) ($sub['id'] ?? ''));
                if ($subId === '' || isset($seen[$subId])) {
                    continue;
                }
                $queue[] = $subId;
            }
        }

        return array_values(array_unique($collected));
    }
}
