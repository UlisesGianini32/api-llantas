<?php

namespace App\Services\Autopartes\Meli;

use Illuminate\Support\Facades\Cache;

class AutomotivePartMeliRequestBudget
{
    public function consume(): void
    {
        $key = $this->key();
        Cache::add($key, 0, now()->endOfDay());
        $used = (int) Cache::increment($key);

        if ($used > $this->limit()) {
            Cache::decrement($key);

            throw new AutomotivePartMeliException(
                'Se alcanzó el límite diario de solicitudes de metadatos de Mercado Libre.',
                'daily_request_limit',
            );
        }
    }

    public function remaining(): int
    {
        return max(0, $this->limit() - (int) Cache::get($this->key(), 0));
    }

    private function limit(): int
    {
        return max(1, (int) config('autopartes_meli.max_daily_requests', 100));
    }

    private function key(): string
    {
        return 'autopartes:meli:requests:'.now()->toDateString();
    }
}
