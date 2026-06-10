<?php

namespace App\Domain\Order\Http\Controllers;

use App\Domain\Order\Actions\CreateDebitNoteAction;
use App\Domain\Order\Models\DebitNote;
use App\Domain\Order\Models\GoodsReturn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * DebitNoteController
 * 
 * Responsibility: Manage debit notes for returns, adjustments, and chargebacks.
 */
class DebitNoteController extends \App\Http\Controllers\Controller
{
    /**
     * Create a new debit note
     */
    public function store(Request $request, CreateDebitNoteAction $action): JsonResponse
    {
        $request->validate([
            'po_id' => 'required|uuid|exists:purchase_orders,id',
            'invoice_id' => 'nullable|uuid|exists:invoices,id',
            'return_id' => 'nullable|uuid|exists:returns,id',
            'buyer_company_id' => 'required|uuid|exists:companies,id',
            'vendor_company_id' => 'required|uuid|exists:companies,id',
            'type' => 'required|in:return_refund,price_adjustment,credit_memo,charge_back',
            'line_items' => 'required|array|min:1',
            'line_items.*.description' => 'required|string',
            'line_items.*.quantity' => 'required|numeric|min:0',
            'line_items.*.unit_price' => 'required|numeric|min:0',
            'line_items.*.amount' => 'required|numeric|min:0',
            'tax_rate' => 'nullable|string',
            'currency' => 'nullable|string|max:3',
            'reason_for_debit' => 'nullable|string',
            'related_invoice_number' => 'nullable|string',
        ]);

        try {
            $debitNote = $action->execute($request->all());

            return response()->json([
                'message' => 'Debit note created successfully.',
                'debit_note' => $debitNote->load('purchaseOrder', 'return', 'buyerCompany', 'vendorCompany'),
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    /**
     * Create debit note from return
     */
    public function createFromReturn(Request $request, string $returnId, CreateDebitNoteAction $action): JsonResponse
    {
        $request->validate([
            'invoice_id' => 'nullable|uuid|exists:invoices,id',
            'related_invoice_number' => 'nullable|string',
        ]);

        try {
            $return = GoodsReturn::findOrFail($returnId);

            $debitNote = $action->executeFromReturn($return, array_merge(
                $request->all(),
                ['created_by' => $request->user()->id ?? null]
            ));

            return response()->json([
                'message' => 'Debit note created from return successfully.',
                'debit_note' => $debitNote->load('return', 'purchaseOrder', 'buyerCompany', 'vendorCompany'),
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    /**
     * Get debit note by ID
     */
    public function show(string $id): JsonResponse
    {
        $debitNote = DebitNote::with('invoice', 'return', 'purchaseOrder', 'buyerCompany', 'vendorCompany',
            'issuedByUser', 'acknowledgedByUser', 'settledByUser', 'disputedByUser')
            ->findOrFail($id);

        return response()->json(['debit_note' => $debitNote]);
    }

    /**
     * List debit notes for a company
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
            $query = DebitNote::where(function ($q) use ($companyId) {
                $q->where('buyer_company_id', $companyId)
                  ->orWhere('vendor_company_id', $companyId);
            });

            if ($request->has('po_id')) {
                $query->where('po_id', $request->input('po_id'));
            }

            if ($request->has('type')) {
                $query->where('type', $request->input('type'));
            }

            if ($request->has('status')) {
                $query->where('status', $request->input('status'));
            }

            $perPage = $request->input('per_page', 10);
            $debitNotes = $query->with('purchaseOrder', 'return', 'buyerCompany', 'vendorCompany')->orderBy('created_at', 'desc')->paginate($perPage);

            return response()->json($debitNotes);
        } catch (\PDOException $e) {
            // Table doesn't exist yet
            \Log::warning('DebitNotes table not found: ' . $e->getMessage());
            return response()->json([
                'data' => [],
                'current_page' => 1,
                'per_page' => 10,
                'total' => 0,
                'last_page' => 1,
                'message' => 'Debit notes feature is being initialized'
            ]);
        } catch (\Exception $e) {
            \Log::error('DebitNote index error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'company_id' => $request->input('company_id')
            ]);
            return response()->json([
                'data' => [],
                'current_page' => 1,
                'per_page' => 10,
                'total' => 0,
                'last_page' => 1,
                'message' => 'Debit notes feature is being initialized'
            ]);
        }
    }

    /**
     * Issue debit note
     */
    public function issue(Request $request, string $id): JsonResponse
    {
        $debitNote = DebitNote::findOrFail($id);

        if ($debitNote->status !== 'draft') {
            return response()->json(['message' => 'Only draft debit notes can be issued.'], 422);
        }

        $debitNote->update([
            'status' => 'issued',
            'issued_by_user_id' => $request->user()->id,
            'issued_at' => now(),
        ]);

        return response()->json([
            'message' => 'Debit note issued successfully.',
            'debit_note' => $debitNote,
        ]);
    }

    /**
     * Acknowledge debit note
     */
    public function acknowledge(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'acknowledgment_notes' => 'nullable|string',
        ]);

        $debitNote = DebitNote::findOrFail($id);

        if (!$debitNote->canBeAcknowledged()) {
            return response()->json(['message' => 'Debit note cannot be acknowledged at this status.'], 422);
        }

        $debitNote->update([
            'status' => 'acknowledged',
            'acknowledged_by_user_id' => $request->user()->id,
            'acknowledged_at' => now(),
            'acknowledgment_notes' => $request->input('acknowledgment_notes'),
        ]);

        return response()->json([
            'message' => 'Debit note acknowledged successfully.',
            'debit_note' => $debitNote,
        ]);
    }

    /**
     * Settle debit note
     */
    public function settle(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'settlement_method' => 'required|in:credit_memo,cash_refund,offset_invoice,other',
            'settlement_notes' => 'nullable|string',
        ]);

        $debitNote = DebitNote::findOrFail($id);

        if (!$debitNote->canBeSettled()) {
            return response()->json(['message' => 'Debit note cannot be settled at this status.'], 422);
        }

        $debitNote->update([
            'status' => 'settled',
            'settled_by_user_id' => $request->user()->id,
            'settled_at' => now(),
            'settlement_method' => $request->input('settlement_method'),
            'settlement_notes' => $request->input('settlement_notes'),
        ]);

        return response()->json([
            'message' => 'Debit note settled successfully.',
            'debit_note' => $debitNote,
        ]);
    }

    /**
     * Dispute debit note
     */
    public function dispute(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'dispute_reason' => 'required|string',
        ]);

        $debitNote = DebitNote::findOrFail($id);

        if (!$debitNote->canBeDisputed()) {
            return response()->json(['message' => 'Debit note cannot be disputed at this status.'], 422);
        }

        $debitNote->markAsDisputed($request->input('dispute_reason'), $request->user()->id);

        return response()->json([
            'message' => 'Debit note marked as disputed.',
            'debit_note' => $debitNote,
        ]);
    }

    /**
     * Resolve dispute
     */
    public function resolveDispute(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'dispute_resolution' => 'required|string',
        ]);

        $debitNote = DebitNote::findOrFail($id);

        if ($debitNote->status !== 'disputed') {
            return response()->json(['message' => 'Only disputed debit notes can be resolved.'], 422);
        }

        $debitNote->resolveDispute($request->input('dispute_resolution'), $request->user()->id);

        return response()->json([
            'message' => 'Dispute resolved successfully.',
            'debit_note' => $debitNote,
        ]);
    }
}
