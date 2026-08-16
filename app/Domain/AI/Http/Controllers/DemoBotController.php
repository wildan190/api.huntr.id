<?php

namespace App\Domain\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Domain\AI\Services\DemoBotService;
use App\Domain\Rfq\Models\Rfq;
use App\Domain\Negotiation\Models\Negotiation;
use App\Domain\Order\Models\PurchaseOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * DemoBotController
 *
 * Endpoint API untuk kontrol dan interaksi dengan 5 AI Vendor Bots (Demo Mode).
 */
class DemoBotController extends Controller
{
    private DemoBotService $demoBotService;

    public function __construct(DemoBotService $demoBotService)
    {
        $this->demoBotService = $demoBotService;
    }

    /**
     * Dapatkan status mode demo & daftar 5 bot vendor.
     */
    public function getBotRoster(): JsonResponse
    {
        return response()->json([
            'demo_mode' => config('app.demo_mode', false),
            'bots' => DemoBotService::BOT_ROSTER,
            'message' => '5 AI Vendor Bots aktif untuk melayani Buyer dalam Mode Demo.'
        ]);
    }

    /**
     * Hasilkan 5 Penawaran Proposal AI Bot unik untuk 1 PR/RFQ.
     */
    public function generateBotsForRfq(Rfq $rfq): JsonResponse
    {
        try {
            $proposals = $this->demoBotService->generateFiveVendorBotsForRfq($rfq);

            return response()->json([
                'message' => 'Berhasil membuat 5 penawaran AI Bot vendor unik untuk PR ini.',
                'total_proposals' => count($proposals),
                'proposals' => $proposals,
            ], 201);
        } catch (\Exception $e) {
            Log::error('DemoBotController: generateBotsForRfq failed', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Gagal menghasilkan penawaran AI bot: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Pemicu AI Bot merespons negosiasi dari buyer.
     */
    public function respondNegotiation(Negotiation $negotiation): JsonResponse
    {
        try {
            $result = $this->demoBotService->handleBotNegotiation($negotiation);

            return response()->json([
                'message' => 'AI Bot vendor berhasil merespons negosiasi.',
                'result' => $result,
            ]);
        } catch (\Exception $e) {
            Log::error('DemoBotController: respondNegotiation failed', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Gagal memproses negosiasi AI bot: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Pemicu AI Bot mengonfirmasi Purchase Order (PO).
     */
    public function confirmPo(PurchaseOrder $po): JsonResponse
    {
        try {
            $updatedPo = $this->demoBotService->handleBotConfirmPo($po);

            return response()->json([
                'message' => 'AI Bot vendor berhasil mengonfirmasi Purchase Order.',
                'po' => $updatedPo,
            ]);
        } catch (\Exception $e) {
            Log::error('DemoBotController: confirmPo failed', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Gagal mengonfirmasi PO: ' . $e->getMessage()
            ], 500);
        }
    }
}
