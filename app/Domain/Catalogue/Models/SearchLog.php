<?php

namespace App\Domain\Catalogue\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Model untuk mencatat setiap aktivitas pencarian produk.
 *
 * @property int $id
 * @property string $keyword
 * @property string $source  regular | ai
 * @property string|null $company_id
 * @property string|null $ip_address
 * @property \Illuminate\Support\Carbon $searched_at
 */
class SearchLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'keyword',
        'source',
        'company_id',
        'ip_address',
        'searched_at',
    ];

    protected $casts = [
        'searched_at' => 'datetime',
    ];

    /**
     * Log satu pencarian secara fire-and-forget.
     * Tidak melempar exception agar tidak menginterupsi search response.
     */
    public static function record(string $keyword, string $source = 'regular', ?string $companyId = null): void
    {
        $keyword = trim(mb_strtolower($keyword));
        if ($keyword === '' || mb_strlen($keyword) < 2) {
            return;
        }

        try {
            static::create([
                'keyword'    => mb_substr($keyword, 0, 255),
                'source'     => $source,
                'company_id' => $companyId,
                'ip_address' => request()?->ip(),
                'searched_at' => now(),
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('SearchLog::record failed', ['error' => $e->getMessage()]);
        }
    }
}
