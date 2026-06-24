<?php

namespace App\Domain\Order\Http\Controllers;

use App\Domain\Order\Actions\AwardVendorAction;
use App\Domain\Order\Actions\GetPurchaseOrdersAction;
use App\Domain\Order\Actions\ConfirmPurchaseOrderAction;
use App\Domain\Order\Actions\UpdatePoTrackingStatusAction;
use App\Domain\Order\Http\Requests\AwardVendorRequest;
use App\Domain\Order\Http\Requests\GetPurchaseOrdersRequest;
use App\Domain\Order\Models\PurchaseOrder;
use App\Domain\Order\Models\DeliveryOrder;
use App\Domain\Order\Models\Invoice;
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

        if (!$poData)
            abort(404);

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

        if (!$poData)
            abort(404);

        return view('print.invoice', ['invoice' => $invoice, 'po' => $poData]);
    }

    /**
     * Print Delivery Order (HTML for Ctrl+P).
     */
    public function printDo(\App\Domain\Order\Models\DeliveryOrder $deliveryOrder, GetPurchaseOrdersAction $action)
    {
        $deliveryOrder->load(['goodsReceipts', 'handedByUser', 'receivedByUser', 'witnessUser']);
        $po = $deliveryOrder->purchaseOrder;
        $data = $action->execute(['company_id' => $po->buyer_company_id, 'search' => $po->po_number]);
        $poData = $data['data'][0] ?? null;

        if (!$poData)
            abort(404);

        return view('print.do', ['do' => $deliveryOrder, 'po' => $poData]);
    }

    /**
     * Arrange Delivery (Vendor releases Delivery Order).
     */
    public function arrangeDelivery(Request $request, PurchaseOrder $po, \App\Domain\Order\Actions\ReleaseDeliveryOrderAction $action): JsonResponse
    {
        $vendorCompanyId = $request->input('company_id');
        $trackingNumber = $request->input('tracking_number');

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
    public function signDoHandedBy(Request $request, \App\Domain\Order\Models\DeliveryOrder $deliveryOrder): JsonResponse
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

        $deliveryOrder->update([
            'handed_by_user_id' => $request->input('handed_by_user_id'),
            'handed_by_name' => $request->input('handed_by_name'),
            'handed_by_position' => $request->input('handed_by_position'),
            'handed_by_signed_at' => now(),
        ]);

        // Refresh to get latest data and load relationships
        $deliveryOrder->refresh();
        $deliveryOrder->load(['purchaseOrder', 'handedByUser', 'receivedByUser', 'witnessUser']);

        return response()->json([
            'message' => 'Signature recorded successfully.',
            'do' => [
                'id' => $deliveryOrder->id,
                'do_number' => $deliveryOrder->do_number,
                'tracking_number' => $deliveryOrder->tracking_number,
                'delivery_address' => $deliveryOrder->delivery_address,
                'status' => $deliveryOrder->status,
                'handed_by_user_id' => $deliveryOrder->handed_by_user_id,
                'handed_by_name' => $deliveryOrder->handed_by_name,
                'handed_by_position' => $deliveryOrder->handed_by_position,
                'handed_by_signed_at' => $deliveryOrder->handed_by_signed_at?->toIso8601String(),
                'received_by_user_id' => $deliveryOrder->received_by_user_id,
                'received_by_name' => $deliveryOrder->received_by_name,
                'received_by_position' => $deliveryOrder->received_by_position,
                'received_by_signed_at' => $deliveryOrder->received_by_signed_at?->toIso8601String(),
                'witness_user_id' => $deliveryOrder->witness_user_id,
                'witness_name' => $deliveryOrder->witness_name,
                'witness_position' => $deliveryOrder->witness_position,
                'witness_signed_at' => $deliveryOrder->witness_signed_at?->toIso8601String(),
                'purchase_order' => $deliveryOrder->purchaseOrder,
                'handed_by_user' => $deliveryOrder->handedByUser,
                'received_by_user' => $deliveryOrder->receivedByUser,
                'witness_user' => $deliveryOrder->witnessUser,
            ],
            'signature_status' => [
                'handed_by_signed' => $deliveryOrder->handed_by_signed_at !== null,
                'received_by_signed' => $deliveryOrder->received_by_signed_at !== null,
                'witness_signed' => $deliveryOrder->witness_signed_at !== null,
                'fully_signed' => $deliveryOrder->isFullySigned(),
            ],
        ]);
    }

    /**
     * Sign Delivery Order as received-by party (buyer)
     */
    public function signDoReceivedBy(Request $request, \App\Domain\Order\Models\DeliveryOrder $deliveryOrder): JsonResponse
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

        $deliveryOrder->update([
            'received_by_user_id' => $request->input('received_by_user_id'),
            'received_by_name' => $request->input('received_by_name'),
            'received_by_position' => $request->input('received_by_position'),
            'received_by_signed_at' => now(),
        ]);

        // Refresh to get latest data and load relationships
        $deliveryOrder->refresh();
        $deliveryOrder->load(['purchaseOrder', 'handedByUser', 'receivedByUser', 'witnessUser']);

        return response()->json([
            'message' => 'Signature recorded successfully.',
            'do' => [
                'id' => $deliveryOrder->id,
                'do_number' => $deliveryOrder->do_number,
                'tracking_number' => $deliveryOrder->tracking_number,
                'delivery_address' => $deliveryOrder->delivery_address,
                'status' => $deliveryOrder->status,
                'handed_by_user_id' => $deliveryOrder->handed_by_user_id,
                'handed_by_name' => $deliveryOrder->handed_by_name,
                'handed_by_position' => $deliveryOrder->handed_by_position,
                'handed_by_signed_at' => $deliveryOrder->handed_by_signed_at?->toIso8601String(),
                'received_by_user_id' => $deliveryOrder->received_by_user_id,
                'received_by_name' => $deliveryOrder->received_by_name,
                'received_by_position' => $deliveryOrder->received_by_position,
                'received_by_signed_at' => $deliveryOrder->received_by_signed_at?->toIso8601String(),
                'witness_user_id' => $deliveryOrder->witness_user_id,
                'witness_name' => $deliveryOrder->witness_name,
                'witness_position' => $deliveryOrder->witness_position,
                'witness_signed_at' => $deliveryOrder->witness_signed_at?->toIso8601String(),
                'purchase_order' => $deliveryOrder->purchaseOrder,
                'handed_by_user' => $deliveryOrder->handedByUser,
                'received_by_user' => $deliveryOrder->receivedByUser,
                'witness_user' => $deliveryOrder->witnessUser,
            ],
            'signature_status' => [
                'handed_by_signed' => $deliveryOrder->handed_by_signed_at !== null,
                'received_by_signed' => $deliveryOrder->received_by_signed_at !== null,
                'witness_signed' => $deliveryOrder->witness_signed_at !== null,
                'fully_signed' => $deliveryOrder->isFullySigned(),
            ],
        ]);
    }

    /**
     * Vendor updates the tracking status of a PO (Packing / In Transit / Delivered).
     */
    public function updateTrackingStatus(Request $request, PurchaseOrder $po, UpdatePoTrackingStatusAction $action): JsonResponse
    {
        $vendorCompanyId = $request->input('company_id');
        $newStatus = $request->input('status');
        $note = $request->input('note');

        if (!$vendorCompanyId) {
            return response()->json(['message' => 'Company ID is required.'], 400);
        }
        if (!$newStatus) {
            return response()->json(['message' => 'Status is required.'], 400);
        }

        $vendorCompany = Company::findOrFail($vendorCompanyId);

        return response()->json([
            'po' => $action->execute($vendorCompany, $po, $newStatus, $note)
        ]);
    }

    /**
     * Public endpoint: track a shipment by PO Number or Tracking Number (resi).
     * No authentication required.
     */
    public function publicTrack(Request $request): JsonResponse
    {
        $poNumber = $request->query('po_number');
        $trackingNumber = $request->query('tracking_number');

        if (!$poNumber && !$trackingNumber) {
            return response()->json(['message' => 'Please provide po_number or tracking_number.'], 400);
        }

        $po = null;

        if ($poNumber) {
            $po = PurchaseOrder::with(['deliveryOrders', 'vendor', 'buyer'])
                ->where('po_number', $poNumber)
                ->first();
        }

        if (!$po && $trackingNumber) {
            $do = DeliveryOrder::with(['purchaseOrder.vendor', 'purchaseOrder.buyer', 'purchaseOrder.deliveryOrders'])
                ->where('tracking_number', $trackingNumber)
                ->first();
            $po = $do?->purchaseOrder;
        }

        if (!$po) {
            return response()->json(['message' => 'No shipment found with the provided details.'], 404);
        }

        // Build status labels map
        $statusLabels = [
            'issued' => 'PO Issued',
            'published' => 'PO Issued',
            'confirmed' => 'PO Confirmed',
            'paid' => 'Payment Received',
            'packing' => 'Goods Being Packed',
            'in_transit' => 'Goods In Transit',
            'delivery' => 'Goods In Transit',
            'delivered' => 'Goods Delivered',
            'completed' => 'Order Completed',
            'done' => 'Order Completed',
        ];

        // Reconstruct a normalized timeline
        $timeline = $po->tracking_timeline ?? [];

        // Ensure base entries exist if timeline is empty/partial
        $existingStatuses = collect($timeline)->pluck('status')->toArray();

        $baseSteps = [
            ['status' => 'issued', 'label' => 'PO Issued', 'timestamp' => $po->created_at->toIso8601String()],
            ['status' => 'confirmed', 'label' => 'PO Confirmed', 'timestamp' => null],
            ['status' => 'paid', 'label' => 'Payment Received', 'timestamp' => null],
            ['status' => 'packing', 'label' => 'Goods Being Packed', 'timestamp' => null],
            ['status' => 'in_transit', 'label' => 'Goods In Transit', 'timestamp' => null],
            ['status' => 'delivered', 'label' => 'Goods Delivered', 'timestamp' => null],
        ];

        // Merge timeline data into base steps
        $timelineMap = collect($timeline)->keyBy('status')->toArray();
        $deliveryOrders = $po->deliveryOrders;

        foreach ($baseSteps as &$step) {
            if (isset($timelineMap[$step['status']])) {
                $step['timestamp'] = $timelineMap[$step['status']]['timestamp'];
                $step['actor_name'] = $timelineMap[$step['status']]['actor_name'] ?? null;
                $step['note'] = $timelineMap[$step['status']]['note'] ?? null;
                $step['completed'] = true;
            } else {
                // Derive from PO status for older POs without timeline
                $step['completed'] = $this->isStatusReached($po->status, $step['status']);
                $step['actor_name'] = null;
                $step['note'] = null;
            }

            // Attach tracking number info for in_transit step
            if ($step['status'] === 'in_transit' && $deliveryOrders->isNotEmpty()) {
                $step['tracking_numbers'] = $deliveryOrders->pluck('tracking_number')->filter()->values()->toArray();
                $step['do_numbers'] = $deliveryOrders->pluck('do_number')->filter()->values()->toArray();
            }
        }
        unset($step);

        return response()->json([
            'po_number' => $po->po_number,
            'current_status' => $po->status,
            'current_label' => $statusLabels[$po->status] ?? ucfirst($po->status),
            'vendor_name' => $po->vendor?->name ?? $po->vendor_name ?? 'N/A',
            'buyer_name' => $po->buyer?->name ?? 'N/A',
            'order_date' => $po->order_date?->format('Y-m-d') ?? $po->created_at->format('Y-m-d'),
            'tracking_numbers' => $deliveryOrders->pluck('tracking_number')->filter()->values()->toArray(),
            'timeline' => $baseSteps,
        ]);
    }

    /**
     * Helper: check if a given status has been reached in the delivery lifecycle.
     */
    private function isStatusReached(string $currentStatus, string $targetStatus): bool
    {
        $order = ['issued', 'published', 'confirmed', 'paid', 'packing', 'in_transit', 'delivery', 'delivered', 'completed', 'done'];
        $currentIdx = array_search($currentStatus, $order);
        $targetIdx = array_search($targetStatus, $order);

        if ($currentIdx === false || $targetIdx === false)
            return false;
        return $currentIdx >= $targetIdx;
    }

    /**
     * Public endpoint: verify a document signature by type + id + role.
     * Used by the Trust Page (/verify) after scanning a QR code.
     */
    public function verifySignature(Request $request): JsonResponse
    {
        $docType = $request->query('type');  // invoice | do | bast
        $docId = $request->query('id');
        $role = $request->query('role');  // vendor | received-by | handed-by | etc.

        if (!$docType || !$docId) {
            return response()->json(['message' => 'Missing required parameters: type, id'], 400);
        }

        switch ($docType) {
            case 'invoice':
                $invoice = Invoice::with('purchaseOrder.vendor', 'purchaseOrder.buyer')->find($docId);
                if (!$invoice) {
                    return response()->json(['valid' => false, 'message' => 'Invoice not found'], 404);
                }
                $po = $invoice->purchaseOrder;
                return response()->json([
                    'valid' => true,
                    'doc_type' => 'invoice',
                    'doc_number' => $po?->po_number ?? $invoice->id,
                    'doc_label' => $invoice->type === 'proforma' ? 'Proforma Invoice' : 'Tax Invoice',
                    'issued_at' => $invoice->created_at?->toIso8601String(),
                    'status' => $invoice->status,
                    'vendor_name' => $po?->vendor?->name ?? $po?->vendor_name ?? 'N/A',
                    'buyer_name' => $po?->buyer?->name ?? 'N/A',
                    'signatures' => [
                        [
                            'role' => 'vendor',
                            'label' => 'Issued By (Vendor)',
                            'signer_name' => $invoice->vendor_signed_name,
                            'signed_at' => $invoice->vendor_signed_at?->toIso8601String(),
                            'is_signed' => !empty($invoice->vendor_signed_at),
                        ],
                    ],
                ]);

            case 'do':
                $do = DeliveryOrder::with('purchaseOrder.vendor', 'purchaseOrder.buyer')->find($docId);
                if (!$do) {
                    return response()->json(['valid' => false, 'message' => 'Delivery Order not found'], 404);
                }
                $po = $do->purchaseOrder;
                return response()->json([
                    'valid' => true,
                    'doc_type' => 'do',
                    'doc_number' => $do->do_number,
                    'doc_label' => 'Delivery Order',
                    'issued_at' => $do->created_at?->toIso8601String(),
                    'status' => $do->status,
                    'tracking_number' => $do->tracking_number,
                    'vendor_name' => $po?->vendor?->name ?? 'N/A',
                    'buyer_name' => $po?->buyer?->name ?? 'N/A',
                    'signatures' => [
                        [
                            'role' => 'handed-by',
                            'label' => 'Delivered By (Vendor)',
                            'signer_name' => $do->handed_by_name,
                            'signer_position' => $do->handed_by_position,
                            'signed_at' => $do->handed_by_signed_at?->toIso8601String(),
                            'is_signed' => !empty($do->handed_by_signed_at),
                        ],
                        [
                            'role' => 'received-by',
                            'label' => 'Received By (Buyer)',
                            'signer_name' => $do->received_by_name,
                            'signer_position' => $do->received_by_position,
                            'signed_at' => $do->received_by_signed_at?->toIso8601String(),
                            'is_signed' => !empty($do->received_by_signed_at),
                        ],
                    ],
                ]);

            case 'bast':
                // Try to find BAST model dynamically
                $bastModel = '\\App\\Domain\\Order\\Models\\Bast';
                if (!class_exists($bastModel)) {
                    return response()->json(['valid' => false, 'message' => 'BAST not supported'], 404);
                }
                $bast = $bastModel::with('purchaseOrder', 'vendorCompany', 'buyerCompany')->find($docId);
                if (!$bast) {
                    return response()->json(['valid' => false, 'message' => 'BAST not found'], 404);
                }
                return response()->json([
                    'valid' => true,
                    'doc_type' => 'bast',
                    'doc_number' => $bast->bast_number,
                    'doc_label' => 'Berita Acara Serah Terima (BAST)',
                    'issued_at' => $bast->created_at?->toIso8601String(),
                    'status' => $bast->status,
                    'vendor_name' => $bast->vendorCompany?->name ?? 'N/A',
                    'buyer_name' => $bast->buyerCompany?->name ?? 'N/A',
                    'signatures' => [
                        [
                            'role' => 'handed-by',
                            'label' => 'Handed By (Vendor)',
                            'signer_name' => $bast->handed_by_name,
                            'signer_position' => $bast->handed_by_position,
                            'signed_at' => $bast->handed_by_signed_at?->toIso8601String(),
                            'is_signed' => !empty($bast->handed_by_signed_at),
                        ],
                        [
                            'role' => 'received-by',
                            'label' => 'Received By (Buyer)',
                            'signer_name' => $bast->received_by_name,
                            'signer_position' => $bast->received_by_position,
                            'signed_at' => $bast->received_by_signed_at?->toIso8601String(),
                            'is_signed' => !empty($bast->received_by_signed_at),
                        ],
                    ],
                ]);

            default:
                return response()->json(['valid' => false, 'message' => 'Unknown document type'], 400);
        }
    }
}
