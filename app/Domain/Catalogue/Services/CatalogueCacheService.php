<?php

namespace App\Domain\Catalogue\Services;

use App\Domain\Catalogue\Models\Catalogue;
use Illuminate\Support\Facades\Cache;

class CatalogueCacheService
{
    /**
     * Cache duration for catalogue details (5 minutes)
     */
    const DETAIL_CACHE_DURATION = 300;
    
    /**
     * Cache duration for catalogue listings (2 minutes)
     */
    const LISTING_CACHE_DURATION = 120;
    
    /**
     * Cache duration for SEO data (10 minutes)
     */
    const SEO_CACHE_DURATION = 600;
    
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
        
        return Cache::get($cacheKey);
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
        
        Cache::put($cacheKey, $data, self::DETAIL_CACHE_DURATION);
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
        
        return Cache::get($cacheKey);
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
        
        Cache::put($cacheKey, $data, self::LISTING_CACHE_DURATION);
        
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
        
        Cache::put($cacheKey, $data, self::SEO_CACHE_DURATION);
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
        // We could also implement a tag-based system here if needed
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
     * Get listing cache key
     *
     * @param array $params
     * @return string
     */
    private function getListingCacheKey(array $params): string
    {
        // Remove page from cache key as it affects pagination
        $cacheParams = $params;
        unset($cacheParams['page']);
        
        // Sort parameters for consistent cache keys
        ksort($cacheParams);
        
        return "catalogue:listing:" . md5(json_encode($cacheParams));
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
        $keys = Cache::get('catalogue:listing:keys', []);
        if (!in_array($cacheKey, $keys)) {
            $keys[] = $cacheKey;
            Cache::put('catalogue:listing:keys', $keys, 86400); // 24 hours
        }
    }
    
    /**
     * Invalidate all listing caches
     *
     * @return void
     */
    private function invalidateAllListings(): void
    {
        $keys = Cache::get('catalogue:listing:keys', []);
        foreach ($keys as $key) {
            Cache::forget($key);
        }
        Cache::forget('catalogue:listing:keys');
    }
}