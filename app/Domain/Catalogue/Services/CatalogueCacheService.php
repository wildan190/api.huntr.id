<?php

namespace App\Domain\Catalogue\Services;

use App\Domain\Catalogue\Models\Catalogue;
use Illuminate\Support\Facades\Cache;

class CatalogueCacheService
{
    /**
     * Cache duration for catalogue details (24 hours)
     */
    const DETAIL_CACHE_DURATION = 86400;
    
    /**
     * Cache duration for catalogue listings (24 hours)
     */
    const LISTING_CACHE_DURATION = 86400;
    
    /**
     * Cache duration for SEO data (24 hours)
     */
    const SEO_CACHE_DURATION = 86400;

    /**
     * Track last cache hit status
     */
    private bool $lastHit = false;

    public function isLastHit(): bool
    {
        return $this->lastHit;
    }
    
    /**
     * Get catalogue details from cache or database
     *
     * @param int|Catalogue $catalogue
     * @return array|null
     */
    public function getDetails($catalogue): ?array
    {
        $catalogueId = $catalogue instanceof Catalogue ? $catalogue->id : $catalogue;
        $cacheKey = $this->getDetailsCacheKey($catalogueId);
        $data = Cache::get($cacheKey);
        $this->lastHit = !empty($data);
        
        return $data;
    }
    
    /**
     * Store catalogue details in cache
     *
     * @param int|Catalogue $catalogue
     * @param array $data
     * @return void
     */
    public function storeDetails($catalogue, array $data): void
    {
        $catalogueId = $catalogue instanceof Catalogue ? $catalogue->id : $catalogue;
        $cacheKey = $this->getDetailsCacheKey($catalogueId);
        $ttl = (int) env('CATALOGUE_DETAIL_CACHE_TTL', self::DETAIL_CACHE_DURATION);
        
        Cache::put($cacheKey, $data, $ttl);
    }
    
    /**
     * Invalidate catalogue details cache
     *
     * @param int|Catalogue $catalogue
     * @return void
     */
    public function invalidateDetails($catalogue): void
    {
        $catalogueId = $catalogue instanceof Catalogue ? $catalogue->id : $catalogue;
        $cacheKey = $this->getDetailsCacheKey($catalogueId);
        
        Cache::forget($cacheKey);
    }
    
    /**
     * Get catalogue listing from cache
     *
     * @param array $params
     * @return array|null
     */
    public function getListing(array $params): ?array
    {
        $cacheKey = $this->getListingCacheKey($params);
        $data = Cache::get($cacheKey);
        $this->lastHit = !empty($data);
        
        return $data;
    }
    
    /**
     * Store catalogue listing in cache
     *
     * @param array $params
     * @param array $data
     * @return void
     */
    public function storeListing(array $params, array $data): void
    {
        $cacheKey = $this->getListingCacheKey($params);
        $ttl = (int) env('CATALOGUE_LISTING_CACHE_TTL', self::LISTING_CACHE_DURATION);
        
        Cache::put($cacheKey, $data, $ttl);
        
        // Store cache key for later invalidation
        $this->storeListingCacheKey($cacheKey);
    }
    
    /**
     * Get SEO data from cache
     *
     * @param int|Catalogue $catalogue
     * @return array|null
     */
    public function getSeoData($catalogue): ?array
    {
        $catalogueId = $catalogue instanceof Catalogue ? $catalogue->id : $catalogue;
        $cacheKey = $this->getSeoCacheKey($catalogueId);
        
        return Cache::get($cacheKey);
    }
    
    /**
     * Store SEO data in cache
     *
     * @param int|Catalogue $catalogue
     * @param array $data
     * @return void
     */
    public function storeSeoData($catalogue, array $data): void
    {
        $catalogueId = $catalogue instanceof Catalogue ? $catalogue->id : $catalogue;
        $cacheKey = $this->getSeoCacheKey($catalogueId);
        $ttl = (int) env('CATALOGUE_SEO_CACHE_TTL', self::SEO_CACHE_DURATION);
        
        Cache::put($cacheKey, $data, $ttl);
    }
    
    /**
     * Invalidate SEO data cache
     *
     * @param int|Catalogue $catalogue
     * @return void
     */
    public function invalidateSeoData($catalogue): void
    {
        $catalogueId = $catalogue instanceof Catalogue ? $catalogue->id : $catalogue;
        $cacheKey = $this->getSeoCacheKey($catalogueId);
        
        Cache::forget($cacheKey);
    }
    
    /**
     * Invalidate all catalogue caches
     *
     * @return void
     */
    public function invalidateAll(): void
    {
        // Invalidate all listing caches
        $this->invalidateAllListings();
        
        // Note: Individual catalogue details will be invalidated on update
    }
    
    /**
     * Get details cache key
     *
     * @param int|string $catalogueId
     * @return string
     */
    private function getDetailsCacheKey(int|string $catalogueId): string
    {
        return "catalogue:detail:{$catalogueId}";
    }
    
    /**
     * Get listing cache key with parameter normalization
     *
     * @param array $params
     * @return string
     */
    public function getListingCacheKey(array $params): string
    {
        $normalized = [];
        if (!empty($params['company_id'])) {
            $normalized['company_id'] = (string) $params['company_id'];
        }
        if (!empty($params['search']) && trim((string)$params['search']) !== '') {
            $normalized['search'] = strtolower(trim((string)$params['search']));
        }
        if (!empty($params['category']) && !in_array(strtolower(trim((string)$params['category'])), ['all', ''], true)) {
            $normalized['category'] = strtolower(trim((string)$params['category']));
        }
        $normalized['page'] = !empty($params['page']) ? (int) $params['page'] : 1;
        $normalized['per_page'] = !empty($params['per_page']) ? (int) $params['per_page'] : 20;

        ksort($normalized);

        return "catalogue:listing:" . md5(json_encode($normalized));
    }
    
    /**
     * Get SEO cache key
     *
     * @param int|string $catalogueId
     * @return string
     */
    private function getSeoCacheKey(int|string $catalogueId): string
    {
        return "catalogue:seo:{$catalogueId}";
    }
    
    /**
     * Store listing cache key for later invalidation
     *
     * @param string $cacheKey
     * @return void
     */
    private function storeListingCacheKey(string $cacheKey): void
    {
        try {
            $keys = Cache::get('catalogue:listing:keys', []);
            if (!is_array($keys)) {
                $keys = [];
            }
            if (!in_array($cacheKey, $keys, true)) {
                $keys[] = $cacheKey;
                Cache::put('catalogue:listing:keys', $keys, 86400 * 7); // 7 days
            }
        } catch (\Throwable $e) {
            // Fail silently
        }
    }
    
    /**
     * Invalidate all listing caches
     *
     * @return void
     */
    private function invalidateAllListings(): void
    {
        try {
            $keys = Cache::get('catalogue:listing:keys', []);
            if (is_array($keys)) {
                foreach ($keys as $key) {
                    Cache::forget($key);
                }
            }
            Cache::forget('catalogue:listing:keys');
        } catch (\Throwable $e) {
            // Fail silently
        }
    }
}