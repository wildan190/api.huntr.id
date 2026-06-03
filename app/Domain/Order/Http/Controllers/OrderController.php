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
}
