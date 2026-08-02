<?php

namespace App\Domain\Catalogue\Http\Controllers;

use App\Domain\Catalogue\Actions\GetTrendingSearchAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * TrendingSearchController
 *
 * Endpoint untuk data trending keyword pencarian produk.
 * Digunakan oleh vendor dashboard untuk menampilkan indikator frekuensi.
 */
class TrendingSearchController extends Controller
{
    /**
     * GET /api/analytics/trending-searches
     *
     * Query params:
     *   - limit  (int, default 10) — jumlah keyword yang dikembalikan
     *   - days   (int, default 30) — window waktu dalam hari
     */
    public function index(Request $request, GetTrendingSearchAction $action): JsonResponse
    {
        $request->validate([
            'limit' => 'sometimes|integer|min:1|max:20',
            'days'  => 'sometimes|integer|min:1|max:90',
        ]);

        $result = $action->execute(
            limit: (int) $request->input('limit', 10),
            days:  (int) $request->input('days', 30),
        );

        return response()->json([
            'success' => true,
            'data'    => $result,
        ]);
    }
}
