<?php

namespace App\Domain\Catalogue\Http\Controllers;

use App\Domain\Catalogue\Actions\CreateCatalogueAction;
use App\Domain\Catalogue\Actions\GetCataloguesAction;
use App\Domain\Catalogue\Http\Requests\CreateCatalogueRequest;
use App\Domain\Catalogue\Http\Requests\ImportHistoricalDataRequest;
use App\Domain\Catalogue\Jobs\ImportCatalogueJob;
use App\Domain\Catalogue\Models\Catalogue;
use App\Domain\Company\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CatalogueController
 * 
 * Responsibility: Manage requests related to product catalogue.
 * Pattern: Thin Controller.
 */
class CatalogueController extends \App\Http\Controllers\Controller
{
    /**
     * Display list of catalogues with filters and pagination.
     */
    public function index(Request $request, GetCataloguesAction $action): JsonResponse
    {
        return response()->json($action->execute($request->all()));
    }

    /**
     * Store a new product in the catalogue.
     */
    public function store(CreateCatalogueRequest $request, CreateCatalogueAction $action): JsonResponse
    {
        $item = $action->execute($request->user(), $request->validated());

        return response()->json([
            'message' => 'Product successfully added to catalogue.',
            'data'    => $item
        ], 201);
    }

    /**
     * Import catalogue data from CSV file.
     */
    public function import(ImportHistoricalDataRequest $request): JsonResponse
    {
        $company = Company::findOrFail($request->input('company_id'));

        if ($company->type !== 'vendor') {
            return response()->json(['message' => 'Only Vendors can import catalogues.'], 422);
        }

        $path = $request->file('csv')->store('imports', 'local');
        ImportCatalogueJob::dispatch($company->id, $path);

        return response()->json([
            'message' => 'Catalogue data is being imported into the queue.',
            'queued'  => true,
        ], 200);
    }

    /**
     * Import historical Purchase Order data from CSV file.
     */
    public function importHistoricalPos(ImportHistoricalDataRequest $request): JsonResponse
    {
        $company = Company::findOrFail($request->input('company_id'));

        if ($company->type !== 'buyer') {
            return response()->json(['message' => 'Only Buyers can import historical PO data.'], 422);
        }

        $path = $request->file('csv')->store('imports', 'local');
        \App\Domain\Order\Jobs\ImportHistoricalPoJob::dispatch($company->id, $path);

        return response()->json([
            'message' => 'Purchase Order data is being imported into the queue.',
            'queued'  => true,
        ], 200);
    }

    /**
     * Update product information in the catalogue.
     */
    public function update(CreateCatalogueRequest $request, Catalogue $catalogue): JsonResponse
    {
        $company = Company::findOrFail($request->input('company_id'));

        if ($company->type !== 'vendor') {
            return response()->json(['message' => 'Only Vendors can modify the catalogue.'], 422);
        }

        $data = $request->validated();
        if (isset($data['image']) && $data['image'] instanceof \Illuminate\Http\UploadedFile) {
            $disk = config('filesystems.default') === 's3' ? 's3' : 'public';
            $data['image_path'] = $data['image']->storePublicly('catalogues', $disk);
            unset($data['image']);
        }

        $catalogue->update($data);

        return response()->json([
            'message' => 'Catalogue product successfully updated.',
            'data'    => $catalogue,
        ], 200);
    }

    /**
     * Display product catalogue detail.
     */
    public function show(Catalogue $catalogue): JsonResponse
    {
        return response()->json(['data' => $catalogue->load('company')], 200);
    }
}
