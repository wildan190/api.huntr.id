<?php

namespace App\Domain\Catalogue\Actions;

use App\Domain\Catalogue\Models\Catalogue;
use App\Domain\Catalogue\Models\SearchLog;
use App\Domain\Catalogue\Services\CatalogueCacheService;
use App\Support\KeywordNormalizer;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Action to retrieve catalogue items with filtering and pagination.
 */
class GetCataloguesAction
{
    /**
     * @var CatalogueCacheService
     */
    private $cacheService;

    /**
     * Constructor
     *
     * @param CatalogueCacheService $cacheService
     */
    public function __construct(CatalogueCacheService $cacheService)
    {
        $this->cacheService = $cacheService;
    }

    /**
     * Execute the action.
     *
     * @param array $params
     * @return LengthAwarePaginator
     */
    public function execute(array $params): LengthAwarePaginator
    {
        // Try to get from cache first
        $cachedData = $this->cacheService->getListing($params);
        
        if ($cachedData) {
            return $this->paginateFromCache($cachedData, $params);
        }
        
        $query = Catalogue::query()->with('company');

        if (!empty($params['company_id'])) {
            $query->where('company_id', $params['company_id']);
        } else {
            // Global marketplace filtering
            $query->whereHas('company', function ($q) {
                $q->where('type', 'vendor')
                  ->whereIn('status', ['approved', 'pending']);
            });
        }

        if (!empty($params['search'])) {
            $search = $params['search'];

            // Log pencarian untuk analitik frekuensi
            SearchLog::record($search, 'regular', $params['company_id'] ?? null);

            $tokens = KeywordNormalizer::tokensFromText($search);

            $query->where(function($q) use ($search, $tokens) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('item_code', 'ilike', "%{$search}%")
                  ->orWhere('category', 'ilike', "%{$search}%")
                  ->orWhere('brand', 'ilike', "%{$search}%")
                  ->orWhere('specifications', 'ilike', "%{$search}%");

                foreach ($tokens as $token) {
                    $q->orWhereJsonContains('keywords', $token);
                }

                $q->orWhereHas('company', function ($cq) use ($search, $tokens) {
                    $cq->where('name', 'ilike', "%{$search}%")
                        ->orWhere('industry_type', 'ilike', "%{$search}%");

                    foreach ($tokens as $token) {
                        $cq->orWhereJsonContains('keywords', $token);
                    }
                });
            });
        }

        if (!empty($params['category'])) {
            $query->where('category', $params['category']);
        }

        $page = $params['page'] ?? 1;
        $perPage = $params['per_page'] ?? 20;
        
        $result = $query->orderBy('id', 'desc')->paginate($perPage, ['*'], 'page', $page);
        
        // Store in cache for future requests
        $this->cacheService->storeListing($params, [
            'data' => $result->items(),
            'total' => $result->total(),
            'per_page' => $result->perPage(),
            'current_page' => $result->currentPage(),
            'last_page' => $result->lastPage()
        ]);
        
        return $result;
    }
    
    /**
     * Create paginator from cached data.
     *
     * @param array $cachedData
     * @param array $params
     * @return LengthAwarePaginator
     */
    private function paginateFromCache(array $cachedData, array $params): LengthAwarePaginator
    {
        $page = $params['page'] ?? 1;
        $perPage = $params['per_page'] ?? 20;
        
        return new LengthAwarePaginator(
            $cachedData['data'],
            $cachedData['total'],
            $cachedData['per_page'],
            $page,
            ['path' => request()->url()]
        );
    }
}
