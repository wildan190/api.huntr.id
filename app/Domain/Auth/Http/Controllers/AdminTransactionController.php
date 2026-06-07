<?php

namespace App\Domain\Auth\Http\Controllers;

use App\Domain\Order\Models\PurchaseOrder;
use App\Domain\Order\Models\Invoice;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminTransactionController extends Controller
{
    /**
     * Get all global Purchase Orders and their associated Invoices.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->query('per_page', 10);
        $search = $request->query('search', '');

        $query = PurchaseOrder::with(['buyer', 'vendor', 'invoices', 'deliveryOrders'])
            ->orderBy('created_at', 'desc');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('po_number', 'like', "%{$search}%")
                  ->orWhere('vendor_name', 'like', "%{$search}%")
                  ->orWhereHas('buyer', function ($b) use ($search) {
                      $b->where('name', 'like', "%{$search}%");
                  });
            });
        }

        $pos = $query->paginate($perPage);

        return response()->json($pos);
    }

    /**
     * Get the Escrow Summary (Total of invoices that are pending disbursement).
     */
    public function escrowSummary(): JsonResponse
    {
        // Escrow funds: Invoices that have been approved by buyer but not yet paid to vendor
        // Depending on exact states, this means 'pending_finance' and 'disbursing'.
        
        $heldInvoicesQuery = Invoice::whereIn('status', ['pending_finance', 'disbursing']);

        $totalInvoicesCount = (clone $heldInvoicesQuery)->count();
        $totalEscrowAmount = (clone $heldInvoicesQuery)->sum('amount');
        
        // Also get some recent held invoices for quick viewing
        $recentHeldInvoices = (clone $heldInvoicesQuery)
            ->with(['purchaseOrder.buyer', 'purchaseOrder.vendor'])
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();

        return response()->json([
            'total_escrow_amount' => $totalEscrowAmount,
            'total_invoices_held' => $totalInvoicesCount,
            'recent_held_invoices' => $recentHeldInvoices
        ]);
    }
}
