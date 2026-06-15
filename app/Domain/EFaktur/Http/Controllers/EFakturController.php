<?php

namespace App\Domain\EFaktur\Http\Controllers;

use App\Domain\EFaktur\Actions\CreateEFakturAction;
use App\Domain\EFaktur\Models\EFaktur;
use App\Domain\EFaktur\Services\PajakIoService;
use App\Domain\Order\Models\Bast;
use App\Domain\Order\Models\PurchaseOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class EFakturController extends \App\Http\Controllers\Controller
{
    public function __construct(
        private readonly PajakIoService $pajakIo
    ) {}

    /**
     * Issue e-Faktur for a given BAST.
     * POST /api/efaktur
     */
    public function store(Request $request, CreateEFakturAction $action): JsonResponse
    {
        $request->validate([
            'bast_id' => 'required|uuid|exists:basts,id',
            // Optional overrides
            'signer_name'    => 'nullable|string|max:255',
            'signer_jabatan' => 'nullable|string|max:255',
        ]);

        $bast = Bast::with(['purchaseOrder', 'vendorCompany', 'buyerCompany'])->findOrFail($request->bast_id);
        $po   = $bast->purchaseOrder;

        if (!$po) {
            return response()->json(['message' => 'Purchase Order not found for this BAST.'], 422);
        }

        // Prevent duplicate e-Faktur for same BAST
        $existing = EFaktur::where('bast_id', $bast->id)->first();
        if ($existing) {
            return response()->json([
                'message'  => 'e-Faktur sudah pernah diterbitkan untuk BAST ini.',
                'efaktur'  => $existing,
            ], 200);
        }

        try {
            $efaktur = $action->execute($bast, $po, $request->only(['signer_name', 'signer_jabatan']));

            return response()->json([
                'message' => 'e-Faktur berhasil diterbitkan.',
                'efaktur' => $efaktur,
            ], 201);
        } catch (\Exception $e) {
            Log::error('EFakturController.store error', [
                'bast_id' => $request->bast_id,
                'error'   => $e->getMessage(),
            ]);
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * List e-Fakturs for a company (by vendor or buyer company_id).
     * GET /api/efaktur?company_id=xxx
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->query('company_id');
        if (!$companyId) {
            return response()->json(['message' => 'company_id is required.'], 422);
        }

        $efakturs = EFaktur::with(['purchaseOrder', 'bast'])
            ->whereHas('purchaseOrder', function ($q) use ($companyId) {
                $q->where('vendor_id', $companyId)
                  ->orWhere('buyer_company_id', $companyId);
            })
            ->latest()
            ->paginate(15);

        return response()->json($efakturs);
    }

    /**
     * Show a single e-Faktur with live status from Pajak.io.
     * GET /api/efaktur/{id}
     */
    public function show(string $id): JsonResponse
    {
        $efaktur = EFaktur::with(['purchaseOrder', 'bast', 'invoice'])->findOrFail($id);

        // If we have a transactionId, refresh status from Pajak.io
        if ($efaktur->transaction_id) {
            try {
                $detail = $this->pajakIo->getFakturDetail($efaktur->transaction_id);
                $newStatus = $detail['data']['status'] ?? $detail['status'] ?? $efaktur->status;
                $nofa      = $detail['data']['nofa'] ?? $detail['nofa'] ?? $efaktur->nofa;

                if ($newStatus !== $efaktur->status || $nofa !== $efaktur->nofa) {
                    $efaktur->update([
                        'status'       => $newStatus,
                        'nofa'         => $nofa,
                        'raw_response' => $detail,
                    ]);
                    $efaktur->refresh();
                }
            } catch (\Exception $e) {
                Log::warning('EFakturController.show: Could not refresh from Pajak.io', [
                    'id'    => $id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json(['efaktur' => $efaktur]);
    }

    /**
     * Get e-Faktur PDF URL / stream from Pajak.io.
     * GET /api/efaktur/{id}/pdf
     */
    public function pdf(string $id): JsonResponse
    {
        $efaktur = EFaktur::findOrFail($id);

        if (!$efaktur->transaction_id) {
            return response()->json(['message' => 'transactionId belum tersedia.'], 422);
        }

        try {
            $result = $this->pajakIo->getFakturPdf($efaktur->transaction_id);
            return response()->json(['pdf' => $result]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Cancel an e-Faktur.
     * POST /api/efaktur/{id}/cancel
     */
    public function cancel(string $id): JsonResponse
    {
        $efaktur = EFaktur::findOrFail($id);

        if (!$efaktur->transaction_id) {
            return response()->json(['message' => 'transactionId tidak ditemukan.'], 422);
        }

        try {
            $result = $this->pajakIo->cancelFaktur($efaktur->transaction_id);
            $efaktur->update(['status' => 'CANCELLED', 'raw_response' => $result]);

            return response()->json([
                'message' => 'e-Faktur berhasil dibatalkan.',
                'result'  => $result,
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
