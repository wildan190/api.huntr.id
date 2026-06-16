<?php

namespace App\Domain\Order\Http\Controllers;

use App\Domain\Order\Actions\AwardVendorAction;
use App\Domain\Order\Actions\GetPurchaseOrdersAction;
use App\Domain\Order\Actions\ConfirmPurchaseOrderAction;
use App\Domain\Order\Http\Requests\AwardVendorRequest;
use App\Domain\Order\Http\Requests\GetPurchaseOrdersRequest;
use App\Domain\Order\Models\PurchaseOrder;
use App\Domain\Company\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Domain\Rfq\Models\Rfq;
use App\Domain\Proposal\Models\Proposal;
use App\Domain\Auth\Models\User;

/**
 * OrderController
 * 
 * Tanggung jawab: Mengelola permintaan terkait Purchase Order.
 * Pola: Thin Controller (semua logika berada di Actions).
 */
class OrderController extends \App\Http\Controllers\Controller
{
    /**
     * Menampilkan daftar Purchase Order dengan paginasi dan pencarian.
     */
    public function index(GetPurchaseOrdersRequest $request, GetPurchaseOrdersAction $action): JsonResponse
    {
        return response()->json($action->execute($request->validated()));
    }

    /**
     * Memberikan penghargaan (award) kepada vendor berdasarkan proposal RFQ.
     */
    public function award(AwardVendorRequest $request, AwardVendorAction $action): JsonResponse
    {
        $rfq = Rfq::findOrFail($request->input('rfq_id'));
        $proposal = Proposal::findOrFail($request->input('proposal_id'));
        $manager = User::findOrFail($request->input('manager_id'));
        
        return response()->json([
            'po' => $action->execute($manager, $rfq, $proposal)
        ]);
    }

    /**
     * Vendor mengonfirmasi Purchase Order dan menerbitkan Proforma Invoice.
     */
    public function confirm(Request $request, PurchaseOrder $po, ConfirmPurchaseOrderAction $action): JsonResponse
    {
        $vendorCompanyId = $request->input('company_id');
        if (!$vendorCompanyId) {
            return response()->json(['message' => 'Company ID is required.'], 400);
        }

        $vendorCompany = Company::findOrFail($vendorCompanyId);
        
        return response()->json([
            'po' => $action->execute($vendorCompany, $po)
        ]);
    }

    /**
     * Print Purchase Order (HTML for Ctrl+P).
     */
    public function printPo(PurchaseOrder $po, GetPurchaseOrdersAction $action)
    {
        $data = $action->execute(['company_id' => $po->buyer_company_id, 'search' => $po->po_number]);
        $poData = $data['data'][0] ?? null;

        if (!$poData) abort(404);

        return view('print.po', ['po' => $poData]);
    }

    /**
     * Print Invoice/Proforma (HTML for Ctrl+P).
     */
    public function printInvoice(\App\Domain\Order\Models\Invoice $invoice, GetPurchaseOrdersAction $action)
    {
        $po = $invoice->purchaseOrder;
        $data = $action->execute(['company_id' => $po->buyer_company_id, 'search' => $po->po_number]);
        $poData = $data['data'][0] ?? null;

        if (!$poData) abort(404);

        return view('print.invoice', ['invoice' => $invoice, 'po' => $poData]);
    }

    /**
     * Print Delivery Order (HTML for Ctrl+P).
     */
    public function printDo(\App\Domain\Order\Models\DeliveryOrder $deliveryOrder, GetPurchaseOrdersAction $action)
    {
        $po = $deliveryOrder->purchaseOrder;
        $data = $action->execute(['company_id' => $po->buyer_company_id, 'search' => $po->po_number]);
        $poData = $data['data'][0] ?? null;

        if (!$poData) abort(404);

        return view('print.do', ['do' => $deliveryOrder, 'po' => $poData]);
    }

    /**
     * Arrange Delivery (Vendor releases Delivery Order).
     */
    public function arrangeDelivery(Request $request, PurchaseOrder $po, \App\Domain\Order\Actions\ReleaseDeliveryOrderAction $action): JsonResponse
    {
        $vendorCompanyId = $request->input('company_id');
        $trackingNumber  = $request->input('tracking_number');

        if (!$vendorCompanyId) {
            return response()->json(['message' => 'Company ID is required.'], 400);
        }

        $vendorCompany = Company::findOrFail($vendorCompanyId);

        return response()->json([
            'do' => $action->execute($vendorCompany, $po, $trackingNumber)
        ]);
    }

    /**
     * Vendor publishes an Invoice.
     */
    public function publishInvoice(Request $request, \App\Domain\Order\Models\Invoice $invoice, \App\Domain\Order\Actions\PublishInvoiceAction $action): JsonResponse
    {
        $vendorCompanyId = $request->input('company_id');
        if (!$vendorCompanyId) {
            return response()->json(['message' => 'Company ID is required.'], 400);
        }

        $vendorCompany = Company::findOrFail($vendorCompanyId);

        return response()->json([
            'invoice' => $action->execute($vendorCompany, $invoice)
        ]);
    }

    /**
     * Buyer's Finance team approves an Invoice.
     */
    public function approveInvoice(Request $request, \App\Domain\Order\Models\Invoice $invoice, \App\Domain\Order\Actions\ApproveInvoiceAction $action): JsonResponse
    {
        $buyerCompanyId = $request->input('company_id');
        if (!$buyerCompanyId) {
            return response()->json(['message' => 'Company ID is required.'], 400);
        }

        $buyerCompany = Company::findOrFail($buyerCompanyId);

        return response()->json([
            'invoice' => $action->execute($buyerCompany, $invoice)
        ]);
    }

    /**
     * Sign Delivery Order as handed-by party (vendor)
     */
    public function signDoHandedBy(Request $request, \App\Domain\Order\Models\DeliveryOrder $do): JsonResponse
    {
        $request->validate([
            'handed_by_user_id' => 'required|uuid|exists:users,id',
            'handed_by_name' => 'required|string',
            'handed_by_position' => 'required|string',
        ]);

        $user = $request->user();
        
        // Fresh query to get latest roles from database
        $user->load('roles');
        
        // Check using collection instead of query builder to use loaded data
        $hasManager = $user->roles->contains('slug', 'manager');
        
        \Log::info('signDoHandedBy role check', [
            'user_id' => $user->id,
            'roles' => $user->roles->pluck('slug')->toArray(),
            'has_manager' => $hasManager,
        ]);
        
        if (!$hasManager) {
            return response()->json(['message' => 'Only Manager can sign documents.'], 403);
        }

        $do->update([
            'handed_by_user_id' => $request->input('handed_by_user_id'),
            'handed_by_name' => $request->input('handed_by_name'),
            'handed_by_position' => $request->input('handed_by_position'),
            'handed_by_signed_at' => now(),
        ]);

        // Refresh to get latest data and load relationships
        $do->refresh();
        $do->load(['purchaseOrder', 'handedByUser', 'receivedByUser', 'witnessUser']);

        return response()->json([
            'message' => 'Signature recorded successfully.',
            'do' => [
                'id' => $do->id,
                'do_number' => $do->do_number,
                'tracking_number' => $do->tracking_number,
                'status' => $do->status,
                'handed_by_user_id' => $do->handed_by_user_id,
                'handed_by_name' => $do->handed_by_name,
                'handed_by_position' => $do->handed_by_position,
                'handed_by_signed_at' => $do->handed_by_signed_at?->toIso8601String(),
                'received_by_user_id' => $do->received_by_user_id,
                'received_by_name' => $do->received_by_name,
                'received_by_position' => $do->received_by_position,
                'received_by_signed_at' => $do->received_by_signed_at?->toIso8601String(),
                'witness_user_id' => $do->witness_user_id,
                'witness_name' => $do->witness_name,
                'witness_position' => $do->witness_position,
                'witness_signed_at' => $do->witness_signed_at?->toIso8601String(),
                'purchase_order' => $do->purchaseOrder,
                'handed_by_user' => $do->handedByUser,
                'received_by_user' => $do->receivedByUser,
                'witness_user' => $do->witnessUser,
            ],
            'signature_status' => [
                'handed_by_signed' => $do->handed_by_signed_at !== null,
                'received_by_signed' => $do->received_by_signed_at !== null,
                'witness_signed' => $do->witness_signed_at !== null,
                'fully_signed' => $do->isFullySigned(),
            ],
        ]);
    }

    /**
     * Sign Delivery Order as received-by party (buyer)
     */
    public function signDoReceivedBy(Request $request, \App\Domain\Order\Models\DeliveryOrder $do): JsonResponse
    {
        $request->validate([
            'received_by_user_id' => 'required|uuid|exists:users,id',
            'received_by_name' => 'required|string',
            'received_by_position' => 'required|string',
        ]);

        $user = $request->user();
        
        // Fresh query to get latest roles from database
        $user->load('roles');
        
        // Check using collection instead of query builder to use loaded data
        $hasManager = $user->roles->contains('slug', 'manager');
        
        \Log::info('signDoReceivedBy role check', [
            'user_id' => $user->id,
            'roles' => $user->roles->pluck('slug')->toArray(),
            'has_manager' => $hasManager,
        ]);
        
        if (!$hasManager) {
            return response()->json(['message' => 'Only Manager can sign documents.'], 403);
        }

        $do->update([
            'received_by_user_id' => $request->input('received_by_user_id'),
            'received_by_name' => $request->input('received_by_name'),
            'received_by_position' => $request->input('received_by_position'),
            'received_by_signed_at' => now(),
        ]);

        // Refresh to get latest data and load relationships
        $do->refresh();
        $do->load(['purchaseOrder', 'handedByUser', 'receivedByUser', 'witnessUser']);

        return response()->json([
            'message' => 'Signature recorded successfully.',
            'do' => [
                'id' => $do->id,
                'do_number' => $do->do_number,
                'tracking_number' => $do->tracking_number,
                'status' => $do->status,
                'handed_by_user_id' => $do->handed_by_user_id,
                'handed_by_name' => $do->handed_by_name,
                'handed_by_position' => $do->handed_by_position,
                'handed_by_signed_at' => $do->handed_by_signed_at?->toIso8601String(),
                'received_by_user_id' => $do->received_by_user_id,
                'received_by_name' => $do->received_by_name,
                'received_by_position' => $do->received_by_position,
                'received_by_signed_at' => $do->received_by_signed_at?->toIso8601String(),
                'witness_user_id' => $do->witness_user_id,
                'witness_name' => $do->witness_name,
                'witness_position' => $do->witness_position,
                'witness_signed_at' => $do->witness_signed_at?->toIso8601String(),
                'purchase_order' => $do->purchaseOrder,
                'handed_by_user' => $do->handedByUser,
                'received_by_user' => $do->receivedByUser,
                'witness_user' => $do->witnessUser,
            ],
            'signature_status' => [
                'handed_by_signed' => $do->handed_by_signed_at !== null,
                'received_by_signed' => $do->received_by_signed_at !== null,
                'witness_signed' => $do->witness_signed_at !== null,
                'fully_signed' => $do->isFullySigned(),
            ],
        ]);
    }

    /**
     * Sign Delivery Order as witness
     */
    public function signDoWitness(Request $request, \App\Domain\Order\Models\DeliveryOrder $do): JsonResponse
    {
        $request->validate([
            'witness_user_id' => 'required|uuid|exists:users,id',
            'witness_name' => 'required|string',
            'witness_position' => 'required|string',
        ]);

        $user = $request->user();
        
        // Fresh query to get latest roles from database
        $user->load('roles');
        
        // Check using collection instead of query builder to use loaded data
        $hasManager = $user->roles->contains('slug', 'manager');
        
        \Log::info('signDoWitness role check', [
            'user_id' => $user->id,
            'roles' => $user->roles->pluck('slug')->toArray(),
            'has_manager' => $hasManager,
        ]);
        
        if (!$hasManager) {
            return response()->json(['message' => 'Only Manager can sign documents.'], 403);
        }

        $do->update([
            'witness_user_id' => $request->input('witness_user_id'),
            'witness_name' => $request->input('witness_name'),
            'witness_position' => $request->input('witness_position'),
            'witness_signed_at' => now(),
        ]);

        // Refresh to get latest data and load relationships
        $do->refresh();
        $do->load(['purchaseOrder', 'handedByUser', 'receivedByUser', 'witnessUser']);

        return response()->json([
            'message' => 'Witness signature recorded successfully.',
            'do' => [
                'id' => $do->id,
                'do_number' => $do->do_number,
                'tracking_number' => $do->tracking_number,
                'status' => $do->status,
                'handed_by_user_id' => $do->handed_by_user_id,
                'handed_by_name' => $do->handed_by_name,
                'handed_by_position' => $do->handed_by_position,
                'handed_by_signed_at' => $do->handed_by_signed_at?->toIso8601String(),
                'received_by_user_id' => $do->received_by_user_id,
                'received_by_name' => $do->received_by_name,
                'received_by_position' => $do->received_by_position,
                'received_by_signed_at' => $do->received_by_signed_at?->toIso8601String(),
                'witness_user_id' => $do->witness_user_id,
                'witness_name' => $do->witness_name,
                'witness_position' => $do->witness_position,
                'witness_signed_at' => $do->witness_signed_at?->toIso8601String(),
                'purchase_order' => $do->purchaseOrder,
                'handed_by_user' => $do->handedByUser,
                'received_by_user' => $do->receivedByUser,
                'witness_user' => $do->witnessUser,
            ],
            'signature_status' => [
                'handed_by_signed' => $do->handed_by_signed_at !== null,
                'received_by_signed' => $do->received_by_signed_at !== null,
                'witness_signed' => $do->witness_signed_at !== null,
                'fully_signed' => $do->isFullySigned(),
            ],
        ]);
    }
}
