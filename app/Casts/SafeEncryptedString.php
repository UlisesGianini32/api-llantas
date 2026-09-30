<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class SafeEncryptedString implements CastsAttributes
{
    /**
     * Cast the given value from database.
     * Gracefully decrypts, falling back to legacy plaintext if not encrypted yet.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            // Legacy plaintext token stored before encryption was enabled
            return (string) $value;
        }
    }

    /**
     * Prepare the given value for storage (encrypt at rest).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Avoid double encryption if the incoming value is already encrypted
        try {
            Crypt::decryptString($value);

            return (string) $value;
        } catch (DecryptException) {
            return Crypt::encryptString((string) $value);
        }
    }
}
