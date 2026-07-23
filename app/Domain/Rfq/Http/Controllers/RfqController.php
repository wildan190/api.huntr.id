<?php

namespace App\Domain\Rfq\Http\Controllers;

use App\Domain\Rfq\Actions\CreateRfqAction;
use App\Domain\Rfq\Actions\ApproveRfqAction;
use App\Domain\Rfq\Actions\RejectRfqAction;
use App\Domain\Rfq\Actions\GetRfqsAction;
use App\Domain\Rfq\Http\Requests\CreateRfqRequest;
use App\Domain\Rfq\Models\Rfq;
use App\Domain\Company\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;


/**
 * RfqController
 * 
 * Tanggung jawab: Mengelola permintaan terkait Request for Quotation (RFQ).
 * Pola: Thin Controller.
 */
class RfqController extends \App\Http\Controllers\Controller
{
    /**
     * Menampilkan daftar RFQ dengan filter.
     */
    public function index(Request $request, GetRfqsAction $action): JsonResponse
    {
        return response()->json($action->execute($request->all()));
    }

    /**
     * Menampilkan detail RFQ beserta item dan proposalnya.
     * Items dengan gambar diprioritaskan untuk ditampilkan lebih dulu.
     */
    public function show(Rfq $rfq): JsonResponse
    {
        // Load RFQ dengan relasi yang diperlukan
        $rfqData = $rfq->load([
            'company',
            'user',
            'proposals' => function ($query) {
                $query->with('company');
            }
        ]);

        // Load items dengan prioritas berdasarkan ketersediaan gambar
        $items = $rfq->items()
            ->with(['catalogue.company'])
            ->get()
            ->sortBy([
                // Prioritas 1: Items dengan gambar (image_path tidak null dan tidak empty)
                function ($item) {
                    $hasImage = !empty($item->catalogue->image_path);
                    return $hasImage ? 0 : 1; // 0 = tinggi, 1 = rendah
                },
                // Prioritas 2: Urutkan berdasarkan nama katalog untuk konsistensi
                function ($item) {
                    return $item->catalogue->name;
                }
            ])
            ->values(); // Reset array keys setelah sorting

        // Set items yang sudah diurutkan ke RFQ
        $rfqData->setRelation('items', $items);

        return response()->json([
            'rfq' => $rfqData
        ], 200);
    }

    /**
     * Membuat RFQ baru (Purchase Requisition).
     */
    public function store(CreateRfqRequest $request, CreateRfqAction $action): JsonResponse
    {
        $company = Company::findOrFail($request->input('company_id'));

        if ($company->type !== 'buyer') {
            return response()->json(['message' => 'Hanya perusahaan Buyer yang dapat membuat RFQ.'], 422);
        }

        $data = $request->validated();

        // Debug: Log jumlah item yang diterima untuk menyelidiki masalah cart 15 item vs 9 item
        Log::info('DEBUG: RFQ Creation - Items received', [
            'total_items_count' => count($data['items'] ?? []),
            'raw_request_items' => $request->all()['items'] ?? 'NO_ITEMS',
            'validated_items' => $data['items'] ?? 'NO_VALIDATED_ITEMS',
            'request_all_keys' => array_keys($request->all()),
        ]);

        $documentPath = null;
        if ($request->hasFile('document')) {
            $diskName = config('filesystems.default');
            \Illuminate\Support\Facades\Log::info('Uploading RFQ document', ['disk' => $diskName, 'bucket' => config('filesystems.disks.' . $diskName . '.bucket')]);
            $documentPath = $request->file('document')->storePublicly('rfq_documents', $diskName);
        }

        $rfq = $action->execute(
            $company,
            $data['title'],
            $data['description'] ?? '',
            $data['items'],
            $data['user_id'] ?? null,
            $data['status'] ?? 'pending_approval',
            $data['duration_days'] ?? 7,
            $documentPath,
            $data['delivery_point'] ?? null
        );

        return response()->json(['rfq' => $rfq], 201);
    }

    /**
     * Menyetujui RFQ oleh manajer pembelian.
     */
    public function approve(Request $request, Rfq $rfq, ApproveRfqAction $action): JsonResponse
    {
        // Use authenticated user instead of manager_id from request
        $manager = $request->user();

        if (!$manager) {
            return response()->json([
                'message' => 'Authentication required.',
                'error' => 'User not authenticated'
            ], 401);
        }

        return response()->json([
            'rfq' => $action->execute($manager, $rfq)
        ], 200);
    }

    /**
     * Menolak RFQ oleh manajer/approver yang berwenang.
     */
    public function reject(Request $request, Rfq $rfq, RejectRfqAction $action): JsonResponse
    {
        try {
            // Get the authenticated user from the request context
            $rejector = $request->user();

            if (!$rejector) {
                return response()->json([
                    'message' => 'Authentication required.',
                    'error' => 'User not authenticated'
                ], 401);
            }

            // Debug: Log the authenticated user info
            Log::info('Reject RFQ by user:', ['user_id' => $rejector->id, 'user_name' => $rejector->name]);

            $validation = $request->validate([
                'reason' => 'nullable|string|max:1000'
            ]);

            $reason = $request->input('reason');

            $rejectedRfq = $action->execute($rejector, $rfq, $reason);

            return response()->json([
                'message' => 'RFQ has been rejected successfully.',
                'rfq' => $rejectedRfq->load(['company', 'user'])
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Validation error in reject RFQ:', [
                'errors' => $e->errors(),
                'request_data' => $request->all()
            ]);

            return response()->json([
                'message' => 'Failed to reject RFQ.',
                'error' => 'Validation failed: ' . implode(', ', $e->errors()),
                'validation_errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error rejecting RFQ:', [
                'message' => $e->getMessage(),
                'authenticated_user' => $request->user()?->id
            ]);

            return response()->json([
                'message' => 'Failed to reject RFQ.',
                'error' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Mendapatkan perankingan proposal untuk RFQ tertentu.
     */
    public function rankings(Rfq $rfq): JsonResponse
    {
        $rankings = $rfq->proposals()
            ->with(['company', 'items.rfqItem.catalogue'])
            ->orderBy('price_offer', 'asc')
            ->get()
            ->map(function ($proposal, $index) {
                return [
                    'rank' => $index + 1,
                    'proposal' => $proposal,
                    'is_winner' => $proposal->winner_status === 'awarded' || $proposal->winner_status === 'approved'
                ];
            });

        return response()->json(['rankings' => $rankings], 200);
    }

    /**
     * Mengundang vendor untuk ikut serta dalam RFQ.
     */
    public function inviteVendor(Request $request, Rfq $rfq): JsonResponse
    {
        $request->validate([
            'whatsapp' => 'required|string',
        ]);

        $whatsapp = preg_replace('/[^0-9]/', '', $request->input('whatsapp'));
        // Normalize to international format: replace leading 0 with 62
        if (str_starts_with($whatsapp, '0')) {
            $whatsapp = '62' . substr($whatsapp, 1);
        }

        $frontendUrl = config('app.frontend_url', 'https://app.huntr.id');
        $rfqLink = $frontendUrl . "/rfq/" . $rfq->id;

        $message = "Hello! You have been invited to submit a quotation for RFQ #{$rfq->id} - {$rfq->title}.\n\nRegister on Huntr.id to view the details and submit your proposal:\n{$rfqLink}";

        $whatsappLink = "https://wa.me/" . $whatsapp . "?text=" . urlencode($message);

        return response()->json([
            'message' => 'Invitation link generated successfully.',
            'whatsapp_link' => $whatsappLink
        ], 200);
    }
}
