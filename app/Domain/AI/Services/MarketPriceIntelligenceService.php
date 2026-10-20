<?php

namespace App\Domain\AI\Services;

use Carbon\Carbon;

/**
 * Resolves a defensible unit-price estimate from current market listings and
 * the buyer's own transaction history. It never invents a price.
 */
class MarketPriceIntelligenceService
{
    public function resolve(?array $historical, array $web): array
    {
        $webPrice = (float) ($web['avg_price'] ?? 0);
        $webSamples = (int) ($web['sample_count'] ?? 0);
        $historicalPrice = (float) ($historical['avg_price'] ?? 0);
        $historicalSamples = (int) ($historical['sample_count'] ?? 0);

        $hasWeb = $webPrice > 0 && $webSamples >= 3;
        $hasHistorical = $historicalPrice > 0 && $historicalSamples >= 2;

        if ($hasWeb && $hasHistorical) {
            $ageDays = $this->ageInDays($historical['last_date'] ?? null);
            $difference = abs($webPrice - $historicalPrice) / max($webPrice, $historicalPrice);

            if ($difference <= 0.25) {
                $historicalWeight = $ageDays !== null && $ageDays <= 30 ? 0.45 : ($ageDays !== null && $ageDays <= 90 ? 0.30 : 0.15);
                $price = round(($historicalPrice * $historicalWeight) + ($webPrice * (1 - $historicalWeight)));

                return [
                    'price' => $price,
                    'status' => 'market_triangulated',
                    'confidence' => $this->confidence($web, $ageDays, true),
                    'note' => sprintf(
                        'Terverifikasi dari pasar saat ini dan %d transaksi historis%s. Estimasi memakai median pasar dengan bobot histori berdasarkan umur data.',
                        $historicalSamples,
                        $ageDays === null ? '' : " (histori {$ageDays} hari lalu)"
                    ),
                ];
            }

            return [
                'price' => round($webPrice),
                'status' => 'web_market_reference',
                'confidence' => $this->confidence($web, $ageDays),
                'note' => sprintf(
                    'Harga pasar terbaru dipakai karena berbeda %d%% dari histori%s. Konfirmasi melalui RFQ sebelum pemesanan.',
                    round($difference * 100),
                    $ageDays === null ? '' : " ({$ageDays} hari lalu)"
                ),
            ];
        }

        if ($hasWeb) {
            return [
                'price' => round($webPrice),
                'status' => 'web_market_reference',
                'confidence' => $this->confidence($web),
                'note' => sprintf(
                    'Median pasar dari %d listing relevan pada %d domain. Harga perlu dikonfirmasi melalui RFQ.',
                    $webSamples,
                    $this->domainCount($web)
                ),
            ];
        }

        if ($hasHistorical) {
            $ageDays = $this->ageInDays($historical['last_date'] ?? null);
            return [
                'price' => round($historicalPrice),
                'status' => 'historical_reference',
                'confidence' => $ageDays !== null && $ageDays <= 90 ? 'medium' : 'low',
                'note' => sprintf(
                    'Referensi %d transaksi historis%s. Belum ada cukup listing pasar terbaru untuk memvalidasi harga ini.',
                    $historicalSamples,
                    $ageDays === null ? '' : " (terakhir {$ageDays} hari lalu)"
                ),
            ];
        }

        return ['price' => 0.0, 'status' => 'rfq_required', 'confidence' => 'unavailable', 'note' => null];
    }

    private function confidence(array $web, ?int $historyAgeDays = null, bool $triangulated = false): string
    {
        $samples = (int) ($web['sample_count'] ?? 0);
        $domains = $this->domainCount($web);
        $min = (float) ($web['min_price'] ?? 0);
        $max = (float) ($web['max_price'] ?? 0);
        $median = (float) ($web['avg_price'] ?? 0);
        $spread = $median > 0 ? (($max - $min) / $median) : 1;

        if ($triangulated && $samples >= 5 && $domains >= 2 && $spread <= 0.35 && ($historyAgeDays === null || $historyAgeDays <= 90)) {
            return 'high';
        }

        return $samples >= 4 && $domains >= 2 && $spread <= 0.50 ? 'medium' : 'low';
    }

    private function domainCount(array $web): int
    {
        return count(array_unique(array_filter(array_map(
            fn (array $source) => mb_strtolower(trim((string) ($source['source'] ?? parse_url((string) ($source['link'] ?? ''), PHP_URL_HOST)))),
            (array) ($web['sources'] ?? [])
        ))));
    }

    private function ageInDays(mixed $date): ?int
    {
        if (! $date) {
            return null;
        }

        try {
            return max(0, Carbon::parse($date)->startOfDay()->diffInDays(now()->startOfDay()));
        } catch (\Throwable) {
            return null;
        }
    }
}
