<?php

namespace App\Services\MercadoLibre\PriceManager;

use App\Models\MeliBeautyScheduledDiscount;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class MeliBeautyPromotionWindow
{
    public const TIMEZONE = 'America/Mexico_City';

    public function shouldApply(MeliBeautyScheduledDiscount $promotion, ?CarbonInterface $at = null): bool
    {
        return (bool) $promotion->active && $this->contains($promotion, $at);
    }

    public function contains(MeliBeautyScheduledDiscount $promotion, ?CarbonInterface $at = null): bool
    {
        $occurrence = $this->currentOccurrence($promotion, $at);

        return $occurrence !== null;
    }

    /** @return array{start: CarbonImmutable, end: CarbonImmutable}|null */
    public function currentOccurrence(MeliBeautyScheduledDiscount $promotion, ?CarbonInterface $at = null): ?array
    {
        $bounds = $this->bounds($promotion);
        if ($bounds === null) {
            return null;
        }

        $now = CarbonImmutable::instance($at ?? now())->setTimezone(self::TIMEZONE);
        if ($now->lessThan($bounds['start']) || ! $now->lessThan($bounds['end'])) {
            return null;
        }

        $startTime = $this->time($promotion->starts_at);
        $endTime = $this->time($promotion->ends_at);
        $overnight = $startTime > $endTime;

        if (! $overnight) {
            $start = $now->startOfDay()->setTimeFromTimeString($startTime);
            $end = $now->startOfDay()->setTimeFromTimeString($endTime);
        } elseif ($now->format('H:i:s') >= $startTime) {
            $start = $now->startOfDay()->setTimeFromTimeString($startTime);
            $end = $start->addDay()->startOfDay()->setTimeFromTimeString($endTime);
        } elseif ($now->format('H:i:s') < $endTime) {
            $end = $now->startOfDay()->setTimeFromTimeString($endTime);
            $start = $end->subDay()->startOfDay()->setTimeFromTimeString($startTime);
        } else {
            return null;
        }

        return $start->lessThan($bounds['end'])
            && $end->greaterThan($bounds['start'])
            && ! $now->lessThan($start)
            && $now->lessThan($end)
                ? ['start' => $start, 'end' => $end]
                : null;
    }

    /** @return array{start: CarbonImmutable, end: CarbonImmutable}|null */
    public function bounds(MeliBeautyScheduledDiscount $promotion): ?array
    {
        if (blank($promotion->starts_on) || blank($promotion->ends_on)) {
            return null;
        }

        $startTime = $this->time($promotion->starts_at);
        $endTime = $this->time($promotion->ends_at);
        if ($startTime === '' || $endTime === '' || $startTime === $endTime) {
            return null;
        }

        try {
            $start = CarbonImmutable::parse($this->date($promotion->starts_on).' '.$startTime, self::TIMEZONE);
            $end = CarbonImmutable::parse($this->date($promotion->ends_on).' '.$endTime, self::TIMEZONE);
        } catch (\Throwable) {
            return null;
        }

        return $end->greaterThan($start) ? ['start' => $start, 'end' => $end] : null;
    }

    public function status(MeliBeautyScheduledDiscount $promotion, ?CarbonInterface $at = null): string
    {
        if (! $promotion->active) {
            return 'disabled';
        }
        $bounds = $this->bounds($promotion);
        if ($bounds === null) {
            return 'requires_configuration';
        }

        $now = CarbonImmutable::instance($at ?? now())->setTimezone(self::TIMEZONE);
        if ($now->lessThan($bounds['start'])) {
            return 'scheduled';
        }
        if (! $now->lessThan($bounds['end'])) {
            return 'finished';
        }

        return $this->contains($promotion, $now) ? 'in_window' : 'outside_hours';
    }

    public function overlaps(MeliBeautyScheduledDiscount $first, MeliBeautyScheduledDiscount $second): bool
    {
        $firstOccurrences = $this->occurrences($first);
        $secondOccurrences = $this->occurrences($second);
        $i = 0;
        $j = 0;

        while (isset($firstOccurrences[$i], $secondOccurrences[$j])) {
            $a = $firstOccurrences[$i];
            $b = $secondOccurrences[$j];
            if ($a['start']->lessThan($b['end']) && $b['start']->lessThan($a['end'])) {
                return true;
            }

            $a['end']->lessThanOrEqualTo($b['end']) ? $i++ : $j++;
        }

        return false;
    }

    /** @return list<array{start: CarbonImmutable, end: CarbonImmutable}> */
    private function occurrences(MeliBeautyScheduledDiscount $promotion): array
    {
        $bounds = $this->bounds($promotion);
        if ($bounds === null) {
            return [];
        }

        $startTime = $this->time($promotion->starts_at);
        $endTime = $this->time($promotion->ends_at);
        $overnight = $startTime > $endTime;
        $date = CarbonImmutable::parse($this->date($promotion->starts_on), self::TIMEZONE)->startOfDay();
        $lastStartDate = CarbonImmutable::parse($this->date($promotion->ends_on), self::TIMEZONE)->startOfDay();
        if ($overnight) {
            $lastStartDate = $lastStartDate->subDay();
        }

        $occurrences = [];
        while ($date->lessThanOrEqualTo($lastStartDate)) {
            $start = $date->setTimeFromTimeString($startTime);
            $end = $date->setTimeFromTimeString($endTime);
            if ($overnight) {
                $end = $end->addDay();
            }
            if ($start->lessThan($bounds['end']) && $end->greaterThan($bounds['start'])) {
                $occurrences[] = ['start' => $start, 'end' => $end];
            }
            $date = $date->addDay();
        }

        return $occurrences;
    }

    private function time(mixed $value): string
    {
        $time = substr(trim((string) $value), 0, 8);

        if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time) === 1) {
            $time .= ':00';
        }

        return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d$/', $time) === 1 ? $time : '';
    }

    private function date(mixed $value): string
    {
        return $value instanceof CarbonInterface ? $value->format('Y-m-d') : substr((string) $value, 0, 10);
    }
}
