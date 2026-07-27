<?php

namespace App\Domain\Catalogue\Http\Controllers;

use App\Domain\Catalogue\Actions\DeleteAdminCatalogueAction;
use App\Domain\Catalogue\Actions\GetAdminCataloguesAction;
use App\Domain\Catalogue\Actions\StoreAdminCatalogueAction;
use App\Domain\Catalogue\Actions\UpdateAdminCatalogueAction;
use App\Domain\Catalogue\Http\Requests\StoreAdminCatalogueRequest;
use App\Domain\Catalogue\Http\Requests\UpdateAdminCatalogueRequest;
use App\Domain\Catalogue\Models\Catalogue;
use App\Domain\Catalogue\Services\CatalogueCacheService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AdminCatalogueController extends Controller
{
    /**
     * Get all global catalogue items.
     */
    public function index(Request $request, GetAdminCataloguesAction $action): JsonResponse
    {
        return response()->json($action->execute(
            $request->only('search'),
            (int) $request->query('per_page', 10)
        ));
    }

    /**
     * Store a new catalogue item on behalf of a vendor.
     */
    public function store(StoreAdminCatalogueRequest $request, StoreAdminCatalogueAction $action, CatalogueCacheService $cacheService): JsonResponse
    {
        try {
            $item = $action->execute($request->validated());
            
            // Invalidate all catalogue caches since we added new product
            $cacheService->invalidateAll();

            return response()->json([
                'message' => 'Product successfully added to vendor catalog.',
                'data'    => $item
            ], 201);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }
    }

    /**
     * Update an existing catalogue item.
     */
    public function update(
        UpdateAdminCatalogueRequest $request,
        Catalogue $catalogue,
        UpdateAdminCatalogueAction $action,
        CatalogueCacheService $cacheService
    ): JsonResponse {
        $item = $action->execute($catalogue, $request->validated());
        
        // Invalidate cache for updated catalogue
        $cacheService->invalidateDetails($catalogue);
        $cacheService->invalidateSeoData($catalogue);
        $cacheService->invalidateAll();

        return response()->json([
            'message' => 'Product successfully updated.',
            'data' => $item,
        ]);
    }

    /**
     * Delete a catalogue item.
     */
    public function destroy(Catalogue $catalogue, DeleteAdminCatalogueAction $action, CatalogueCacheService $cacheService): JsonResponse
    {
        $action->execute($catalogue);
        
        // Invalidate cache for deleted catalogue
        $cacheService->invalidateDetails($catalogue);
        $cacheService->invalidateSeoData($catalogue);
        $cacheService->invalidateAll();

        return response()->json(['message' => 'Product successfully deleted from catalog.']);
    }
}
