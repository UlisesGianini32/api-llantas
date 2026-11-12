<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Resolutor aplicativo de usuarios sobre la conexión principal ('users') para entidades
 * almacenadas físicamente en la base independiente 'autopartes' sin generar JOIN/whereHas SQL entre bases.
 */
final class AutopartesUserDirectory
{
    /**
     * @param  iterable<int|string|null>  $userIds
     * @return Collection<int, User>
     */
    public static function mapByIds(iterable $userIds): Collection
    {
        $ids = [];
        foreach ($userIds as $id) {
            if ($id !== null && $id !== '' && (int) $id > 0) {
                $ids[] = (int) $id;
            }
        }

        $uniqueIds = array_values(array_unique($ids));
        if ($uniqueIds === []) {
            return collect();
        }

        return User::query()
            ->whereIn('id', $uniqueIds)
            ->get(['id', 'name', 'email', 'role'])
            ->keyBy('id');
    }

    public static function find(?int $userId): ?User
    {
        if ($userId === null || $userId <= 0) {
            return null;
        }

        return User::query()
            ->select(['id', 'name', 'email', 'role'])
            ->find($userId);
    }

    public static function nameFor(?int $userId): ?string
    {
        return self::find($userId)?->name;
    }

    public static function exists(?int $userId): bool
    {
        if ($userId === null || $userId <= 0) {
            return false;
        }

        return User::query()->whereKey($userId)->exists();
    }

    /** @return Collection<int, User> */
    public static function listSelectable(int $limit = 500): Collection
    {
        return User::query()
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name', 'email']);
    }
}
