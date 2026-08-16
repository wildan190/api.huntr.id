<?php

namespace App\Domain\AI\Services;

use App\Domain\Rfq\Models\Rfq;
use App\Domain\Proposal\Models\Proposal;
use App\Domain\Proposal\Models\ProposalItem;
use App\Domain\Negotiation\Models\Negotiation;
use App\Domain\Negotiation\Models\NegotiationItem;
use App\Domain\Order\Models\PurchaseOrder;
use App\Domain\Company\Models\Company;
use App\Domain\Auth\Models\User;
use App\Domain\Communication\Actions\BroadcastWebsocketNotificationAction;
use App\Domain\Order\Actions\ConfirmPurchaseOrderAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * DemoBotService
 *
 * Mengelola 5 AI Vendor Bots dalam Mode Demo untuk Buyer.
 * Bot ikut tender (5 unik bot per PR), negosiasi AI, dan konfirmasi PO otomatis.
 */
class DemoBotService
{
    private OpenAiService $openAiService;
    private BroadcastWebsocketNotificationAction $broadcastAction;
    private ConfirmPurchaseOrderAction $confirmPoAction;

    // Defined 5 Bot Personas
    public const BOT_ROSTER = [
        [
            'code' => 'BOT_1',
            'name' => 'PT Jaya Express Logistics',
            'email' => 'sales@jayaexpress.demo',
            'phone' => '081299990001',
            'archetype' => 'Harga Termurah & Volume Tinggi',
            'system_instruction' => 'Kamu adalah AI Bot Vendor dari PT Jaya Express Logistics. Strategi kamu: menawarkan HARGA TERMURAH di pasar (diskon 10-15%), waktu pengiriman standar (3-5 hari), garansi 6 bulan, dan pembayaran Net 30. Kamu sangat agresif merebut tender dengan efisiensi biaya.',
            'price_multiplier' => 0.88,
            'delivery_days' => 4,
            'warranty_months' => 6,
            'payment_term' => 'Net 30',
        ],
        [
            'code' => 'BOT_2',
            'name' => 'PT Global Tech Solusindo',
            'email' => 'procurement@globaltech.demo',
            'phone' => '081299990002',
            'archetype' => 'Kualitas Premium & Garansi Panjang 24 Bulan',
            'system_instruction' => 'Kamu adalah AI Bot Vendor dari PT Global Tech Solusindo. Strategi kamu: produk grade A premium resmi, garansi panjang 24 bulan, sertifikasi lengkap. Harga sedikit lebih tinggi (+5-8%) tapi memberikan kepastian kualitas dan dukungan teknis 24/7.',
            'price_multiplier' => 1.06,
            'delivery_days' => 6,
            'warranty_months' => 24,
            'payment_term' => 'Net 60',
        ],
        [
            'code' => 'BOT_3',
            'name' => 'PT Nusantara Supply Co',
            'email' => 'order@nusantarasupply.demo',
            'phone' => '081299990003',
            'archetype' => 'Pengiriman Kilat Express (1-2 Hari)',
            'system_instruction' => 'Kamu adalah AI Bot Vendor dari PT Nusantara Supply Co. Strategi kamu: keunggulan utama pada PENGIRIMAN KILAT (1-2 hari kerja), stok selalu ready di warehouse terdekat, harga standar pasar, garansi 12 bulan, opsi pembayaran COD / Net 14.',
            'price_multiplier' => 1.02,
            'delivery_days' => 2,
            'warranty_months' => 12,
            'payment_term' => 'Net 14',
        ],
        [
            'code' => 'BOT_4',
            'name' => 'PT Indo Flexi Procurement',
            'email' => 'b2b@indoflexi.demo',
            'phone' => '081299990004',
            'archetype' => 'Harga Fleksibel & Sangat Negosiatif',
            'system_instruction' => 'Kamu adalah AI Bot Vendor dari PT Indo Flexi Procurement. Strategi kamu: memasang harga penawaran fleksibel, sangat terbuka terhadap negosiasi buyer, siap memberikan diskon tambahan jika diajukan negosiasi, syarat pembayaran longgar Net 60/90.',
            'price_multiplier' => 0.96,
            'delivery_days' => 5,
            'warranty_months' => 12,
            'payment_term' => 'Net 60 Flexible',
        ],
        [
            'code' => 'BOT_5',
            'name' => 'PT Eco Green Solutions',
            'email' => 'info@ecogreen.demo',
            'phone' => '081299990005',
            'archetype' => 'Eco-Friendly & Bonus Maintenance Gratis',
            'system_instruction' => 'Kamu adalah AI Bot Vendor dari PT Eco Green Solutions. Strategi kamu: kemasan ramah lingkungan, sertifikasi hijau, memberikan bonus layanan maintenance/pemeriksaan gratis 1 tahun, harga seimbang, garansi 18 bulan.',
            'price_multiplier' => 1.00,
            'delivery_days' => 3,
            'warranty_months' => 18,
            'payment_term' => 'Net 30 + 2% Early Pay Discount',
        ]
    ];

    public function __construct(
        OpenAiService $openAiService,
        BroadcastWebsocketNotificationAction $broadcastAction,
        ConfirmPurchaseOrderAction $confirmPoAction
    ) {
        $this->openAiService = $openAiService;
        $this->broadcastAction = $broadcastAction;
        $this->confirmPoAction = $confirmPoAction;
    }

    /**
     * Memastikan 5 Perusahaan & User Bot Vendor tersedia di DB.
     */
    public function ensureBotCompanies(): array
    {
        $companies = [];

        foreach (self::BOT_ROSTER as $botInfo) {
            $company = Company::where('email', $botInfo['email'])
                ->orWhere('name', $botInfo['name'])
                ->first();

            if (!$company) {
                // Buat user bot owner jika belum ada
                $user = User::where('email', $botInfo['email'])->first();
                if (!$user) {
                    $user = User::create([
                        'name' => $botInfo['name'] . ' Representative',
                        'email' => $botInfo['email'],
                        'password' => bcrypt('demo123456'),
                        'phone' => $botInfo['phone'],
                    ]);
                }

                $company = Company::create([
                    'name' => $botInfo['name'],
                    'email' => $botInfo['email'],
                    'phone' => $botInfo['phone'],
                    'type' => 'vendor',
                    'status' => 'approved',
                    'owner_id' => $user->id,
                    'about' => 'AI Demo Vendor Bot: ' . $botInfo['archetype'],
                    'industry_type' => 'General Procurement & Trading',
                    'country' => 'Indonesia',
                    'city' => 'Jakarta Selatan',
                    'address' => 'Gedung Cyber Tower Lt. ' . rand(3, 18) . ', Jakarta Selatan',
                ]);

                // Link user company
                $user->company_id = $company->id;
                $user->save();
            }

            $companies[$botInfo['code']] = [
                'company' => $company,
                'bot_info' => $botInfo
            ];
        }

        return $companies;
    }

    /**
     * Menghasilkan 5 Penawaran Proposal (Bot Ikut Tender) untuk 1 PR/RFQ.
     */
    public function generateFiveVendorBotsForRfq(Rfq $rfq): array
    {
        Log::info("DemoBotService: Generating 5 AI Vendor proposals for RFQ #{$rfq->id}");
        $botCompanies = $this->ensureBotCompanies();
        $rfq->load('items.catalogue');

        $createdProposals = [];

        foreach (self::BOT_ROSTER as $index => $botInfo) {
            $companyObj = $botCompanies[$botInfo['code']]['company'];

            // Skip jika bot ini sudah mengajukan proposal untuk RFQ ini
            $existing = Proposal::where('rfq_id', $rfq->id)
                ->where('company_id', $companyObj->id)
                ->first();

            if ($existing) {
                $createdProposals[] = $existing;
                continue;
            }

            // Hitung harga dasar dari items RFQ
            $totalPriceOffer = 0;
            $proposalItemsData = [];

            foreach ($rfq->items as $item) {
                $baseItemPrice = $item->estimated_price;
                if (!$baseItemPrice || $baseItemPrice <= 0) {
                    $baseItemPrice = $item->catalogue?->price ?? 500000;
                }

                // Variasikan harga item berdasarkan multiplier archetype bot (+- acak 2%)
                $variation = (rand(98, 102) / 100.0);
                $itemOfferPrice = round($baseItemPrice * $botInfo['price_multiplier'] * $variation);
                if ($itemOfferPrice < 1000) $itemOfferPrice = 1000;

                $lineTotal = $itemOfferPrice * ($item->qty ?? 1);
                $totalPriceOffer += $lineTotal;

                $proposalItemsData[] = [
                    'rfq_item_id' => $item->id,
                    'price_offer' => $itemOfferPrice,
                    'qty' => $item->qty ?? 1,
                    'item_name' => $item->catalogue?->name ?? 'Item #' . $item->id,
                ];
            }

            // Minta OpenAI membuat narasi proposal unik untuk bot ini
            $aiProposalNotes = "Penawaran resmi dari {$botInfo['name']} ({$botInfo['archetype']}). Garansi {$botInfo['warranty_months']} bulan, pengiriman {$botInfo['delivery_days']} hari kerja.";
            try {
                $prompt = "Tulis 2 kalimat penawaran tender yang sangat profesional dan meyakinkan untuk RFQ berjudul '{$rfq->title}'. Perusahaan kamu adalah {$botInfo['name']} dengan keunggulan: {$botInfo['archetype']}. Sebutkan keunggulan garansi {$botInfo['warranty_months']} bulan dan pengiriman {$botInfo['delivery_days']} hari.";
                $aiResponse = $this->openAiService->ask($prompt, $botInfo['system_instruction']);
                if (!empty($aiResponse)) {
                    $aiProposalNotes = trim($aiResponse);
                }
            } catch (\Exception $e) {
                Log::warning("DemoBotService: OpenAI proposal note generation failed, using fallback narrative: " . $e->getMessage());
            }

            DB::transaction(function () use ($rfq, $companyObj, $botInfo, $totalPriceOffer, $proposalItemsData, $aiProposalNotes, &$createdProposals) {
                $proposal = Proposal::create([
                    'rfq_id'          => $rfq->id,
                    'company_id'       => $companyObj->id,
                    'price_offer'      => $totalPriceOffer,
                    'delivery_days'    => $botInfo['delivery_days'],
                    'warranty_months'  => $botInfo['warranty_months'],
                    'payment_term'     => $botInfo['payment_term'],
                    'document_path'    => null,
                    'status'           => 'submitted',
                    'winner_status'    => 'pending',
                ]);

                foreach ($proposalItemsData as $itemData) {
                    ProposalItem::create([
                        'proposal_id' => $proposal->id,
                        'rfq_item_id' => $itemData['rfq_item_id'],
                        'price_offer' => $itemData['price_offer'],
                    ]);
                }

                $createdProposals[] = $proposal->load('company', 'items');
            });
        }

        // Notifikasi ke Buyer bahwa 5 proposal bot telah masuk
        if ($rfq->user_id) {
            $this->broadcastAction->execute(
                "5 Penawaran AI Bot Masuk",
                "RFQ '{$rfq->title}' menerima 5 penawaran bot unik dari vendor demo.",
                "test-channel",
                true,
                $rfq->user_id,
                "/rfq/{$rfq->id}",
                ['type' => 'proposals_generated', 'rfq_id' => $rfq->id]
            );
        }

        return $createdProposals;
    }

    /**
     * Memproses Negosiasi dari Buyer menggunakan AI Bot yang sesuai.
     */
    public function handleBotNegotiation(Negotiation $negotiation): array
    {
        $negotiation->load(['proposal.company', 'proposal.rfq', 'proposal.items.rfqItem', 'items.proposalItem']);

        $proposal = $negotiation->proposal;
        if (!$proposal || !$proposal->company) {
            throw new \RuntimeException("Proposal atau Company tidak ditemukan.");
        }

        // Cari archetype bot yang sesuai
        $botConfig = null;
        foreach (self::BOT_ROSTER as $b) {
            if ($b['name'] === $proposal->company->name || str_contains($proposal->company->email, strtolower(str_replace('BOT_', '', $b['code'])))) {
                $botConfig = $b;
                break;
            }
        }

        if (!$botConfig) {
            // Default to Bot 4 (Flexi)
            $botConfig = self::BOT_ROSTER[3];
        }

        $buyerRemarks = $negotiation->buyer_remarks ?? "Buyer meminta negosiasi harga dan syarat pengiriman.";
        $currentPrice = $proposal->price_offer;

        // Hitung total harga negosiasi buyer jika ada item negotiation
        $buyerNegotiatedTotal = 0;
        foreach ($negotiation->items as $negItem) {
            $buyerNegotiatedTotal += ($negItem->negotiated_price * $negItem->negotiated_qty);
        }

        $prompt = <<<PROMPT
Anda adalah AI Bot Penjual dari {$botConfig['name']}.
Profil Anda: {$botConfig['archetype']}
Harga Awal Penawaran Anda: Rp {$currentPrice}
Permintaan Negosiasi dari Buyer: "{$buyerRemarks}"
Harga yang diminta Buyer (jika ada): Rp {$buyerNegotiatedTotal}

Tugas Anda:
1. Putuskan respons negosiasi: 'accepted' (jika permintaan wajar) atau 'counter' (memberikan penawaran balasan yang menguntungkan kedua pihak).
2. Jika 'accepted', set harga baru ke harga buyer. Jika 'counter', berikan diskon 3-7% dari harga awal Anda.
3. Tulis kalimat respons resmi vendor (vendor_remarks) yang sopan, ramah, dan profesional dalam Bahasa Indonesia (2-3 kalimat).

Balas dengan format JSON:
{
  "decision": "accepted" atau "counter",
  "new_price_offer": 0000000,
  "vendor_remarks": "kalimat respons vendor..."
}
PROMPT;

        $aiResult = [];
        try {
            $aiResult = $this->openAiService->askJson($prompt, $botConfig['system_instruction']);
        } catch (\Exception $e) {
            Log::warning("DemoBotService: OpenAI negotiation response failed, using rule-based response", ['error' => $e->getMessage()]);
        }

        $decision = $aiResult['decision'] ?? 'accepted';
        $vendorRemarks = $aiResult['vendor_remarks'] ?? "Terima kasih atas pengajuan negosiasi. Sebagai {$botConfig['name']}, kami menyetujui penawaran harga dan syarat yang diajukan.";

        try {
            app(\App\Domain\Negotiation\Actions\RespondToNegotiationAction::class)->execute(
                $negotiation,
                'accepted',
                $vendorRemarks
            );
        } catch (\Exception $e) {
            Log::warning("DemoBotService: RespondToNegotiationAction failed: " . $e->getMessage());
            $negotiation->update([
                'status' => 'accepted',
                'vendor_remarks' => $vendorRemarks,
            ]);
        }

        return [
            'negotiation' => $negotiation->fresh(['items', 'proposal.company']),
            'vendor_remarks' => $vendorRemarks,
        ];
    }

    /**
     * Memproses Konfirmasi Purchase Order (PO) otomatis oleh AI Bot.
     */
    public function handleBotConfirmPo(PurchaseOrder $po): PurchaseOrder
    {
        $po->load(['vendor', 'buyer', 'rfq']);

        $vendorName = $po->vendor?->name ?? 'Vendor AI Bot';

        if ($po->vendor && $po->status !== 'confirmed') {
            try {
                $po = $this->confirmPoAction->execute($po->vendor, $po);
            } catch (\Exception $e) {
                Log::warning("DemoBotService: confirmPoAction failed: " . $e->getMessage());
            }
        }

        $prompt = <<<PROMPT
Tulis pesan konfirmasi resmi terima Purchase Order (PO #{$po->po_number}) dari perspektif perusahaan supplier '{$vendorName}'.
Sebutkan bahwa PO telah disetujui, pesanan sedang diproses di gudang, dan barang akan dikirimkan tepat waktu sesuai kesepakatan.
Tulis dalam 2-3 kalimat Bahasa Indonesia yang ramah dan profesional.
PROMPT;

        $confirmNote = "PO #{$po->po_number} telah dikonfirmasi dan diterima oleh {$vendorName}. Barang sedang disiapkan untuk pengiriman.";
        try {
            $aiText = $this->openAiService->ask($prompt, "Kamu adalah tim Sales Ops vendor B2B yang menerima PO resmi dari buyer.");
            if (!empty($aiText)) {
                $confirmNote = trim($aiText);
            }
        } catch (\Exception $e) {
            Log::warning("DemoBotService: OpenAI PO confirm text failed: " . $e->getMessage());
        }

        DB::transaction(function () use ($po, $vendorName, $confirmNote) {
            $timeline = $po->tracking_timeline ?? [];
            $timeline[] = [
                'status' => 'confirmed',
                'label' => 'PO Confirmed (AI Bot)',
                'timestamp' => now()->toIso8601String(),
                'actor_name' => $vendorName,
                'actor_type' => 'vendor_bot',
                'note' => $confirmNote,
            ];

            $po->update([
                'status' => 'confirmed',
                'tracking_timeline' => $timeline,
            ]);
        });

        // Broadcast ke buyer
        if ($po->created_by) {
            $this->broadcastAction->execute(
                "PO Dikonfirmasi oleh Vendor Bot",
                "Vendor {$vendorName} mengonfirmasi Purchase Order #{$po->po_number}.",
                "test-channel",
                true,
                $po->created_by,
                "/orders?search={$po->po_number}",
                ['type' => 'po_confirmed', 'po_id' => $po->id]
            );
        }

        return $po->fresh();
    }
}
