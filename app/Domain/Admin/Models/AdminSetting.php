<?php

namespace App\Domain\Admin\Models;

use Illuminate\Database\Eloquent\Model;

class AdminSetting extends Model
{
    protected $table = 'admin_settings';
    protected $primaryKey = 'key';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['key', 'value'];

    /**
     * Ambil semua settings sebagai associative array dengan value yang sudah di-cast.
     */
    public static function allAsArray(): array
    {
        return static::all()->mapWithKeys(function ($row) {
            return [$row->key => static::castValue($row->value)];
        })->toArray();
    }

    /**
     * Ambil satu setting, return default jika tidak ada.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $row = static::find($key);
        return $row ? static::castValue($row->value) : $default;
    }

    /**
     * Set/update satu setting.
     */
    public static function set(string $key, mixed $value): void
    {
        static::updateOrCreate(
            ['key' => $key],
            ['value' => is_bool($value) ? ($value ? 'true' : 'false') : (string) $value]
        );
    }

    /**
     * Cast string value ke tipe yang sesuai.
     */
    private static function castValue(string $value): mixed
    {
        if ($value === 'true')  return true;
        if ($value === 'false') return false;
        if (is_numeric($value)) return $value + 0;
        return $value;
    }
}
