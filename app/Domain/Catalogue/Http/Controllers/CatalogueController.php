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
 * Tanggung jawab: Mengelola permintaan terkait katalog produk.
 * Pola: Thin Controller.
 */
class CatalogueController extends \App\Http\Controllers\Controller
{
    /**
     * Menampilkan daftar katalog dengan filter dan paginasi.
     */
    public function index(Request $request, GetCataloguesAction $action): JsonResponse
    {
        return response()->json($action->execute($request->all()));
    }

    /**
     * Menyimpan produk baru ke dalam katalog.
     */
    public function store(CreateCatalogueRequest $request, CreateCatalogueAction $action): JsonResponse
    {
        $item = $action->execute($request->user(), $request->validated());

        return response()->json([
            'message' => 'Produk berhasil ditambahkan ke katalog.',
            'data'    => $item
        ], 201);
    }

    /**
     * Mengimpor data katalog dari file CSV.
     */
    public function import(ImportHistoricalDataRequest $request): JsonResponse
    {
        $company = Company::findOrFail($request->input('company_id'));

        if ($company->type !== 'vendor') {
            return response()->json(['message' => 'Hanya Vendor yang dapat mengimpor katalog.'], 422);
        }

        $path = $request->file('csv')->store('imports', 'local');
        ImportCatalogueJob::dispatch($company->id, $path);

        return response()->json([
            'message' => 'Data katalog sedang diimpor ke dalam antrean.',
            'queued'  => true,
        ], 200);
    }

    /**
     * Mengimpor data historis Purchase Order dari file CSV.
     */
    public function importHistoricalPos(ImportHistoricalDataRequest $request): JsonResponse
    {
        $company = Company::findOrFail($request->input('company_id'));

        if ($company->type !== 'buyer') {
            return response()->json(['message' => 'Hanya Buyer yang dapat mengimpor data PO historis.'], 422);
        }

        $path = $request->file('csv')->store('imports', 'local');
        \App\Domain\Order\Jobs\ImportHistoricalPoJob::dispatch($company->id, $path);

        return response()->json([
            'message' => 'Data Purchase Order sedang diimpor ke dalam antrean.',
            'queued'  => true,
        ], 200);
    }

    /**
     * Memperbarui informasi produk di katalog.
     */
    public function update(CreateCatalogueRequest $request, Catalogue $catalogue): JsonResponse
    {
        $company = Company::findOrFail($request->input('company_id'));

        if ($company->type !== 'vendor') {
            return response()->json(['message' => 'Hanya Vendor yang dapat mengubah katalog.'], 422);
        }

        $data = $request->validated();
        if (isset($data['image']) && $data['image'] instanceof \Illuminate\Http\UploadedFile) {
            $disk = config('filesystems.default') === 's3' ? 's3' : 'public';
            $data['image_path'] = $data['image']->storePublicly('catalogues', $disk);
            unset($data['image']);
        }

        $catalogue->update($data);

        return response()->json([
            'message' => 'Produk katalog berhasil diperbarui.',
            'data'    => $catalogue,
        ], 200);
    }

    /**
     * Menampilkan detail produk katalog.
     */
    public function show(Catalogue $catalogue): JsonResponse
    {
        return response()->json(['data' => $catalogue->load('company')], 200);
    }
}
