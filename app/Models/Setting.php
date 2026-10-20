<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/** Key/value settings entered in the app. Secrets are stored encrypted with APP_KEY. */
class Setting extends Model
{
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];

    public static function get(string $key): ?string
    {
        return static::find($key)?->value;
    }

    public static function put(string $key, ?string $value): void
    {
        $value === null
            ? static::whereKey($key)->delete()
            : static::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    public static function secret(string $key): ?string
    {
        $value = static::get($key);
        try {
            return $value === null ? null : Crypt::decryptString($value);
        } catch (DecryptException) {
            return null; // APP_KEY changed; the secret has to be entered again
        }
    }

    public static function putSecret(string $key, ?string $value): void
    {
        static::put($key, $value === null ? null : Crypt::encryptString($value));
    }
}
