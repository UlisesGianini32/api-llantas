<?php

namespace App\Jobs;

use App\Models\AutomotivePartMeliPublication;
use App\Services\Autopartes\Publisher\AutomotivePartMeliLivePublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class PublishAutomotivePartToMeliJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout;

    public function __construct(public readonly int $publicationId)
    {
        $this->timeout = max(30, min(180, (int) config('autopartes_meli_publisher.timeout', 30) + 30));
        $this->onQueue('autopartes-meli-publisher');
    }

    public function middleware(): array { return [(new WithoutOverlapping('autopartes-meli-publication:'.$this->publicationId))->expireAfter($this->timeout + 30)->dontRelease()]; }
    public function backoff(): array { return [random_int(10, 30)]; }

    public function handle(AutomotivePartMeliLivePublisher $publisher): void
    {
        $publication = AutomotivePartMeliPublication::query()->find($this->publicationId);
        if ($publication !== null) $publisher->publish($publication);
    }
}
