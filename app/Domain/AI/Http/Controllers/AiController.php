<?php

namespace App\Domain\AI\Http\Controllers;

use App\Domain\AI\Actions\AiSearchCatalogueAction;
use App\Domain\AI\Actions\AiCompareProductsAction;
use App\Domain\AI\Actions\AiRankProposalsAction;
use App\Domain\AI\Actions\AiGeneratePrAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AiController
 *
 * Tanggung jawab: Mengelola semua request terkait fitur AI di platform Huntr.
 * Pattern: Thin Controller — semua logic ada di Actions.
 */
class AiController extends \App\Http\Controllers\Controller
{
    /**
     * POST /api/ai/search
     *
     * Natural language search katalog produk.
     * Body: { query: string, company_id?: string }
     */
    public function search(Request $request, AiSearchCatalogueAction $action): JsonResponse
    {
        $request->validate([
            'query' => 'required|string|min:3|max:500',
        ]);

        try {
            $result = $action->execute(
                $request->input('query'),
                $request->only(['company_id'])
            );

            return response()->json([
                'success'      => true,
                'is_ai_search' => true,
                'intent'       => $result['intent'],
                'ai_summary'   => $result['ai_summary'],
                'data'         => $result['products'],
                'total'        => $result['total'],
            ]);
        } catch (\Exception $e) {
            // Graceful degradation: return empty result jika AI gagal
            return response()->json([
                'success'      => false,
                'is_ai_search' => false,
                'ai_summary'   => null,
                'data'         => [],
                'total'        => 0,
                'error'        => 'AI service tidak tersedia. Silakan gunakan pencarian biasa.',
            ], 200); // tetap 200 agar frontend tidak crash
        }
    }

    /**
     * POST /api/ai/compare-text
     *
     * Generate teks perbandingan produk dari natural language query.
     * Menggunakan pengetahuan eksternal AI (bukan data DB).
     * Body: { query: string }
     */
    public function compareText(Request $request): JsonResponse
    {
        $request->validate([
            'query' => 'required|string|min:5|max:500',
        ]);

        try {
            $genkit = app(\App\Domain\AI\Services\GenkitService::class);
            $text = $genkit->generateComparisonText($request->input('query'));
            return response()->json([
                'success'  => true,
                'markdown' => $text,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success'  => false,
                'markdown' => null,
                'error'    => 'Gagal membuat perbandingan.',
            ], 200);
        }
    }

    /**
     * POST /api/ai/compare
     *
     * Perbandingan AI untuk beberapa produk katalog.
     * Body: { catalogue_ids: string[] }
     */
    public function compare(Request $request, AiCompareProductsAction $action): JsonResponse
    {
        $request->validate([
            'catalogue_ids'   => 'required|array|min:2|max:5',
            'catalogue_ids.*' => 'required|string',
        ]);

        try {
            $result = $action->execute($request->input('catalogue_ids'));

            return response()->json([
                'success'     => true,
                'catalogues'  => $result['catalogues'],
                'ai_analysis' => $result['ai_analysis'],
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error'   => 'AI comparison tidak tersedia saat ini.',
            ], 200);
        }
    }

    /**
     * POST /api/ai/rank-proposals
     *
     * AI assessment dan ranking proposal tender.
     * Body: { rfq_id: string }
     */
    public function rankProposals(Request $request, AiRankProposalsAction $action): JsonResponse
    {
        $request->validate([
            'rfq_id' => 'required|string|exists:rfqs,id',
        ]);

        try {
            $result = $action->execute($request->input('rfq_id'));

            return response()->json([
                'success' => true,
                'data'    => $result,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error'   => 'AI ranking tidak tersedia saat ini.',
                'data'    => ['rankings' => [], 'overall_analysis' => '', 'recommended_winner_id' => null],
            ], 200);
        }
    }

    /**
     * POST /api/ai/generate-pr
     *
     * Auto-generate draft Purchase Requisition dari prompt user.
     * Body: { query: string, catalogue_ids?: string[] }
     */
    public function generatePr(Request $request, AiGeneratePrAction $action): JsonResponse
    {
        $request->validate([
            'query'           => 'required|string|min:10|max:1000',
            'catalogue_ids'   => 'nullable|array',
            'catalogue_ids.*' => 'string',
        ]);

        try {
            $result = $action->execute(
                $request->input('query'),
                $request->input('catalogue_ids', [])
            );

            return response()->json([
                'success' => true,
                'draft'   => $result,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error'   => 'Gagal membuat draft PR. Silakan coba lagi.',
            ], 200);
        }
    }
}
