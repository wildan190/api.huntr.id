<?php

namespace App\Domain\Order\Http\Controllers;

use App\Domain\Order\Actions\CreateBastAction;
use App\Domain\Order\Events\BastIssuedEvent;
use App\Domain\Order\Models\Bast;
use App\Domain\Order\Models\PurchaseOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Filesystem\FilesystemAdapter;

/**
 * BastController
 * 
 * Responsibility: Manage BAST (Berita Acara Serah Terima) document generation and signatures.
 * Located in Order domain as it's part of the purchase order lifecycle.
 */
class BastController extends \App\Http\Controllers\Controller
{
    /**
     * Create BAST for a purchase order
     */
    public function store(Request $request, CreateBastAction $action): JsonResponse
    {
        $request->validate([
            'po_id' => 'required|uuid|exists:purchase_orders,id',
            'goods_receipt_id' => 'nullable|uuid|exists:goods_receipts,id',
            'handed_by_name' => 'required|string|max:255',
            'handed_by_position' => 'required|string|max:255',
            'received_by_name' => 'required|string|max:255',
            'received_by_position' => 'required|string|max:255',
            'witness_name' => 'nullable|string|max:255',
            'witness_position' => 'nullable|string|max:255',
            'items' => 'nullable|array',
            'handover_notes' => 'nullable|string',
        ]);

        try {
            Log::info('BastController.store called', [
                'po_id' => $request->input('po_id'),
                'user_id' => auth()->id() ?? $request->user()->id,
            ]);

            $po = PurchaseOrder::findOrFail($request->input('po_id'));

            $bast = $action->execute($po, $request->all());

            Log::info('BAST created, dispatching event', [
                'bast_id' => $bast->id,
                'bast_number' => $bast->bast_number,
                'buyer_company_id' => $po->buyer_company_id,
            ]);

            // Dispatch event to notify buyer
            event(new BastIssuedEvent($bast, $po->buyer_company_id));

            Log::info('Event dispatched successfully', [
                'bast_id' => $bast->id,
            ]);

            return response()->json([
                'message' => 'BAST created successfully.',
                'bast' => $bast->load('purchaseOrder', 'buyerCompany', 'vendorCompany'),
            ], 201);
        } catch (\Exception $e) {
            Log::error('BastController.store error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
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

            Log::info('BastController.index called', [
                'company_id' => $companyId,
            ]);

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
            $basts = $query->with('purchaseOrder', 'buyerCompany', 'vendorCompany')->orderBy('created_at', 'desc')->paginate($perPage);

            Log::info('BastController.index result', [
                'company_id' => $companyId,
                'count' => $basts->total(),
            ]);

            return response()->json($basts);
        } catch (\PDOException $e) {
            // Table doesn't exist yet
            Log::warning('BAST table not found: ' . $e->getMessage());
            return response()->json([
                'data' => [],
                'current_page' => 1,
                'per_page' => 10,
                'total' => 0,
                'last_page' => 1,
                'message' => 'BAST feature is being initialized'
            ]);
        } catch (\Exception $e) {
            Log::error('BAST index error: ' . $e->getMessage(), [
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

    /**
     * Generate and display BAST PDF in browser (for printing)
     */
    public function showPdf(string $id)
    {
        $bast = Bast::with('purchaseOrder', 'buyerCompany', 'vendorCompany')
            ->findOrFail($id);

        $disk = config('filesystems.default') === 's3' ? 's3' : 'public';
        /** @var FilesystemAdapter $storageDisk */
        $storageDisk = Storage::disk($disk);
        $buyerLogoUrl = null;
        if ($bast->buyerCompany && $bast->buyerCompany->logo_path) {
            $buyerLogoUrl = $storageDisk->url($bast->buyerCompany->logo_path);
        }
        $vendorLogoUrl = null;
        if ($bast->vendorCompany && $bast->vendorCompany->logo_path) {
            $vendorLogoUrl = $storageDisk->url($bast->vendorCompany->logo_path);
        }

        // Return view directly for browser display (user can Ctrl+P)
        return view('print.bast', [
            'bast' => $bast,
            'buyer_logo_url' => $buyerLogoUrl,
            'vendor_logo_url' => $vendorLogoUrl,
        ]);
    }
}
