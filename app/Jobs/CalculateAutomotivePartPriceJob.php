<?php

namespace App\Jobs;

use App\Models\AutomotivePart;
use App\Models\AutomotivePartPriceRule;
use App\Services\Autopartes\MediaPricing\AutomotivePartPriceCalculator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CalculateAutomotivePartPriceJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 60;

    public function __construct(public readonly int $automotivePartId, public readonly ?int $ruleId, public readonly string $expectedFingerprint, public readonly bool $force = false)
    {
        $this->onQueue('autopartes-media-pricing');
    }

    public function uniqueId(): string { return 'autopartes-price:'.$this->automotivePartId.':'.$this->expectedFingerprint; }
    public function uniqueFor(): int { return 3600; }

    public function handle(AutomotivePartPriceCalculator $calculator): void
    {
        $part = AutomotivePart::query()->find($this->automotivePartId);
        $rule = $this->ruleId === null ? null : AutomotivePartPriceRule::query()->find($this->ruleId);
        if ($part === null || ($this->ruleId !== null && $rule === null)) return;
        $preview = $calculator->preview($part, $rule);
        if ($preview['fingerprint'] === null || ! hash_equals($this->expectedFingerprint, $preview['fingerprint'])) return;
        $calculator->calculate($part, $rule, $this->force);
    }
}
