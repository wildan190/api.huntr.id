<?php

namespace App\Domain\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * AiUsageLog
 *
 * Mencatat setiap panggilan ke OpenAI API untuk keperluan tracking quota,
 * billing, dan audit penggunaan AI per perusahaan.
 *
 * @property string  $id
 * @property string|null $company_id
 * @property string|null $user_id
 * @property string  $endpoint
 * @property string  $model
 * @property int     $prompt_tokens
 * @property int     $completion_tokens
 * @property int     $total_tokens
 * @property float   $estimated_cost_usd
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class AiUsageLog extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id',
        'user_id',
        'endpoint',
        'model',
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
        'estimated_cost_usd',
    ];

    protected $casts = [
        'prompt_tokens'      => 'integer',
        'completion_tokens'  => 'integer',
        'total_tokens'       => 'integer',
        'estimated_cost_usd' => 'float',
    ];

    /**
     * Estimasi biaya USD berdasarkan model dan token usage.
     * Harga referensi: gpt-4o-mini input $0.15/1M, output $0.60/1M
     *                  gpt-4o      input $2.50/1M, output $10.00/1M
     */
    public static function estimateCost(string $model, int $promptTokens, int $completionTokens): float
    {
        $rates = [
            'gpt-4o-mini' => ['input' => 0.00000015, 'output' => 0.00000060],
            'gpt-4o'      => ['input' => 0.0000025,  'output' => 0.0000100],
        ];

        $rate = $rates[$model] ?? $rates['gpt-4o-mini'];

        return round(($promptTokens * $rate['input']) + ($completionTokens * $rate['output']), 8);
    }

    /**
     * Ambil ringkasan penggunaan AI per company di bulan ini.
     */
    public static function getMonthlySummary(string $companyId): array
    {
        $rows = static::query()
            ->where('company_id', $companyId)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->selectRaw('COUNT(*) as total_requests, SUM(total_tokens) as total_tokens, SUM(estimated_cost_usd) as total_cost_usd')
            ->first();

        return [
            'total_requests'   => (int) ($rows->total_requests ?? 0),
            'total_tokens'     => (int) ($rows->total_tokens ?? 0),
            'total_cost_usd'   => round((float) ($rows->total_cost_usd ?? 0), 6),
            'month'            => now()->format('F Y'),
        ];
    }
}
