<?php

namespace App\Models\Concerns;

use LogicException;

/** Application-level append-only guard; bulk SQL must never be used for source records. */
trait ImmutableSourceRecord
{
    public static function bootImmutableSourceRecord(): void
    {
        static::updating(function (): never {
            throw new LogicException('El origen documental es inmutable; registre una nueva versión o decisión.');
        });
        static::deleting(function (): never {
            throw new LogicException('No se permite eliminar el origen ni su historial.');
        });
    }
}
