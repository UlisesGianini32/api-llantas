<?php

namespace App\Services\MercadoLibre\Claims;

use App\Models\MeliClaim;
use Illuminate\Database\Eloquent\Builder;

class MeliClaimOperationalCriteria
{
    public function open(MeliClaim $claim): bool
    {
        return ! in_array($claim->status, ['closed', 'resolved'], true);
    }

    public function needsAttention(MeliClaim $claim): bool
    {
        return $this->open($claim)
            && in_array($claim->action_responsible, ['seller', 'respondent'], true);
    }

    public function urgent(MeliClaim $claim): bool
    {
        if (! $this->open($claim)) {
            return false;
        }

        $limit = now()->addDay();

        return ($this->needsAttention($claim) && $claim->due_date?->lte($limit))
            || ((bool) $claim->affects_reputation && $claim->reputation_due_date?->lte($limit));
    }

    public function applyOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', ['closed', 'resolved']);
    }

    public function applyAttention(Builder $query): Builder
    {
        return $this->applyOpen($query)
            ->whereIn('action_responsible', ['seller', 'respondent']);
    }

    public function applyUrgent(Builder $query): Builder
    {
        $limit = now()->addDay();

        return $this->applyOpen($query)->where(function (Builder $query) use ($limit): void {
            $query->where(function (Builder $query) use ($limit): void {
                $query->whereIn('action_responsible', ['seller', 'respondent'])
                    ->whereNotNull('due_date')
                    ->where('due_date', '<=', $limit);
            })->orWhere(function (Builder $query) use ($limit): void {
                $query->where('affects_reputation', true)
                    ->whereNotNull('reputation_due_date')
                    ->where('reputation_due_date', '<=', $limit);
            });
        });
    }
}
