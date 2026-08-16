<?php

namespace App\Domain\AI\Http\Controllers;

use App\Domain\AI\Actions\AiSearchCatalogueAction;
use App\Domain\AI\Actions\AiCompareProductsAction;
use App\Domain\AI\Actions\AiRankProposalsAction;
use App\Domain\AI\Actions\AiGeneratePrAction;
use App\Domain\AI\Actions\RunAgenticProcurementAction;
use App\Domain\AI\Actions\AgenticProcurementChatAction;
use App\Domain\AI\Actions\CreateAgenticPrAction;
use App\Domain\AI\Services\OpenAiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AiController
 *
 * Tanggung jawab: Mengelola semua request terkait fitur AI & Agentic Procurement di platform Huntr.
 * Pattern: Thin Controller — semua logic ada di Actions.
 */
class AiController extends \App\Http\Controllers\Controller
{
    /**
     * POST /api/ai/agentic-procurement/run
     *
     * Menjalankan full autonomous workflow (Search -> Compare -> Formulate PR).
     */
    public function agenticRun(Request $request, RunAgenticProcurementAction $action): JsonResponse
    {
        $request->validate([
            'query'            => 'required|string|min:5|max:2000',
            'company_id'       => 'nullable|string',
            'auto_create_pr'   => 'nullable|boolean',
            'catalogue_ids'    => 'nullable|array',
            'catalogue_ids.*'  => 'string',
        ]);

        try {
            $user = $request->user();
            $result = $action->execute(
                $request->input('query'),
                array_merge($request->all(), [
                    'user_id' => $user?->id,
                ])
            );

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error'   => 'Gagal menjalankan Agentic Procurement: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/ai/agentic-procurement/chat
     *
     * Interaktif multi-turn chat dengan Agentic Procurement AI.
     */
    public function agenticChat(Request $request, AgenticProcurementChatAction $action): JsonResponse
    {
        $request->validate([
            'messages'           => 'required|array|min:1',
            'messages.*.role'    => 'required|string|in:user,assistant,system',
            'messages.*.content' => 'required|string',
            'company_id'         => 'nullable|string',
        ]);

        try {
            $result = $action->execute(
                $request->input('messages'),
                $request->only(['company_id'])
            );

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error'   => 'Gagal memproses percakapan: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/ai/agentic-procurement/create-pr
     *
     * 1-Click membuat PR dari draft yang digenerasi oleh Agentic AI.
     */
    public function agenticCreatePr(Request $request, CreateAgenticPrAction $action): JsonResponse
    {
        $request->validate([
            'company_id' => 'required|string|exists:companies,id',
            'pr_draft'   => 'required|array',
            'pr_draft.suggested_items' => 'required|array|min:1',
        ]);

        try {
            $user = $request->user();
            $rfq = $action->execute(
                $request->input('company_id'),
                $request->input('pr_draft'),
                $user?->id
            );

            return response()->json([
                'success' => true,
                'message' => 'Purchase Requisition berhasil dibuat ke sistem.',
                'rfq'     => $rfq->load(['items.catalogue', 'company']),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error'   => 'Gagal membuat PR: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * POST /api/ai/search
     *
     * Natural language search katalog produk.
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
            return response()->json([
                'success'      => false,
                'is_ai_search' => false,
                'ai_summary'   => null,
                'data'         => [],
                'total'        => 0,
                'error'        => 'AI service tidak tersedia. Silakan gunakan pencarian biasa.',
            ], 200);
        }
    }

    /**
     * POST /api/ai/compare-text
     *
     * Generate teks perbandingan produk dari natural language query.
     */
    public function compareText(Request $request, OpenAiService $openAi): JsonResponse
    {
        $request->validate([
            'query' => 'required|string|min:5|max:500',
        ]);

        try {
            $text = $openAi->generateComparisonText($request->input('query'));
            return response()->json([
                'success'  => true,
                'markdown' => $text,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success'  => false,
                'markdown' => null,
                'error'    => 'Gagal membuat perbandingan: ' . $e->getMessage(),
            ], 200);
        }
    }

    /**
     * POST /api/ai/compare
     *
     * Perbandingan AI untuk beberapa produk katalog.
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
