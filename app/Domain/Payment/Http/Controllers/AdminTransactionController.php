<?php

namespace App\Domain\Payment\Http\Controllers;

use App\Domain\Payment\Actions\GetAdminEscrowSummaryAction;
use App\Domain\Payment\Actions\GetAdminTransactionsAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminTransactionController extends Controller
{
    /**
     * Get all global Purchase Orders and their associated Invoices.
     */
    public function index(Request $request, GetAdminTransactionsAction $action): JsonResponse
    {
        return response()->json($action->execute(
            $request->only('search'),
            (int) $request->query('per_page', 10)
        ));
    }

    /**
     * Get the Escrow Summary (Total of invoices that are pending disbursement).
     */
    public function escrowSummary(GetAdminEscrowSummaryAction $action): JsonResponse
    {
        return response()->json($action->execute());
    }
}
