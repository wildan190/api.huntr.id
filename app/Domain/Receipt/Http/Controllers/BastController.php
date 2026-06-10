<?php

namespace App\Domain\Receipt\Http\Controllers;

use App\Domain\Receipt\Actions\GenerateBastAction;
use App\Domain\Receipt\Models\Bast;
use App\Domain\Receipt\Models\GoodsReceipt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * BastController
 * 
 * Responsibility: Manage BAST (Berita Acara Serah Terima) document generation and signatures.
 */
class BastController extends \App\Http\Controllers\Controller
{
    /**
     * Generate BAST from goods receipt
     */
    public function generateBast(Request $request, GenerateBastAction $action): JsonResponse
    {
        $request->validate([
            'goods_receipt_id' => 'required|uuid|exists:goods_receipts,id',
            'handed_by_name' => 'required|string|max:255',
            'handed_by_position' => 'required|string|max:255',
            'received_by_name' => 'required|string|max:255',
            'received_by_position' => 'required|string|max:255',
            'witness_name' => 'nullable|string|max:255',
            'witness_position' => 'nullable|string|max:255',
            'handover_notes' => 'nullable|string',
        ]);

        try {
            $goodsReceipt = GoodsReceipt::findOrFail($request->input('goods_receipt_id'));

            $bast = $action->execute($goodsReceipt, $request->all());

            return response()->json([
                'message' => 'BAST generated successfully.',
                'bast' => $bast->load('purchaseOrder', 'buyerCompany', 'vendorCompany'),
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    /**
     * Get BAST by ID
     */
    public function show(string $id): JsonResponse
    {
        $bast = Bast::with('purchaseOrder', 'buyerCompany', 'vendorCompany', 'handedByUser', 'receivedByUser', 'witnessUser')
            ->findOrFail($id);

        return response()->json(['bast' => $bast]);
    }

    /**
     * List BASTs for a company
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // Validate only required parameters without database checks first
            $companyId = $request->input('company_id');
            if (!$companyId) {
                return response()->json([
                    'message' => 'company_id is required',
                ], 422);
            }

            // Try to get data
            $query = Bast::where(function ($q) use ($companyId) {
                $q->where('buyer_company_id', $companyId)
                  ->orWhere('vendor_company_id', $companyId);
            });

            if ($request->has('po_id')) {
                $query->where('po_id', $request->input('po_id'));
            }

            if ($request->has('status')) {
                $query->where('status', $request->input('status'));
            }

            $perPage = $request->input('per_page', 10);
            $basts = $query->with('purchaseOrder', 'buyerCompany', 'vendorCompany')->paginate($perPage);

            return response()->json($basts);
        } catch (\PDOException $e) {
            // Table doesn't exist yet
            \Log::warning('BAST table not found: ' . $e->getMessage());
            return response()->json([
                'data' => [],
                'current_page' => 1,
                'per_page' => 10,
                'total' => 0,
                'last_page' => 1,
                'message' => 'BAST feature is being initialized'
            ]);
        } catch (\Exception $e) {
            \Log::error('BAST index error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'company_id' => $request->input('company_id')
            ]);
            return response()->json([
                'data' => [],
                'current_page' => 1,
                'per_page' => 10,
                'total' => 0,
                'last_page' => 1,
                'message' => 'BAST feature is being initialized'
            ]);
        }
    }

    /**
     * Sign BAST as handed-by party (vendor)
     */
    public function signHandedBy(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'handed_by_user_id' => 'required|uuid|exists:users,id',
            'handed_by_name' => 'required|string',
            'handed_by_position' => 'required|string',
        ]);

        $bast = Bast::findOrFail($id);

        $bast->update([
            'handed_by_user_id' => $request->input('handed_by_user_id'),
            'handed_by_name' => $request->input('handed_by_name'),
            'handed_by_position' => $request->input('handed_by_position'),
            'handed_by_signed_at' => now(),
        ]);

        if ($bast->isFullySigned()) {
            $bast->markCompleted();
        }

        return response()->json([
            'message' => 'Signature recorded successfully.',
            'bast' => $bast,
        ]);
    }

    /**
     * Sign BAST as received-by party (buyer)
     */
    public function signReceivedBy(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'received_by_user_id' => 'required|uuid|exists:users,id',
            'received_by_name' => 'required|string',
            'received_by_position' => 'required|string',
        ]);

        $bast = Bast::findOrFail($id);

        $bast->update([
            'received_by_user_id' => $request->input('received_by_user_id'),
            'received_by_name' => $request->input('received_by_name'),
            'received_by_position' => $request->input('received_by_position'),
            'received_by_signed_at' => now(),
        ]);

        if ($bast->isFullySigned()) {
            $bast->markCompleted();
        }

        return response()->json([
            'message' => 'Signature recorded successfully.',
            'bast' => $bast,
        ]);
    }

    /**
     * Sign BAST as witness
     */
    public function signWitness(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'witness_user_id' => 'required|uuid|exists:users,id',
            'witness_name' => 'required|string',
            'witness_position' => 'required|string',
        ]);

        $bast = Bast::findOrFail($id);

        $bast->update([
            'witness_user_id' => $request->input('witness_user_id'),
            'witness_name' => $request->input('witness_name'),
            'witness_position' => $request->input('witness_position'),
            'witness_signed_at' => now(),
        ]);

        if ($bast->isFullySigned()) {
            $bast->markCompleted();
        }

        return response()->json([
            'message' => 'Witness signature recorded successfully.',
            'bast' => $bast,
        ]);
    }
}
