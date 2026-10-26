<?php

namespace App\Domain\Order\Http\Controllers;

use App\Domain\Order\Actions\CreateBastAction;
use App\Domain\Order\Actions\BuildPurchaseOrderPaymentBreakdownAction;
use App\Domain\Order\Events\BastIssuedEvent;
use App\Domain\Order\Models\Bast;
use App\Domain\Order\Models\PurchaseOrder;
use App\Domain\Company\Models\Company;
use App\Domain\Company\Services\CompanyAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Filesystem\FilesystemAdapter;

/**
 * BastController
 * 
 * Responsibility: Manage BAST (Berita Acara Serah Terima) document generation and signatures.
 * Located in Order domain as it's part of the purchase order lifecycle.
 */
class BastController extends \App\Http\Controllers\Controller
{
    /**
     * Create BAST for a purchase order
     */
    public function store(Request $request, CreateBastAction $action, CompanyAccessService $access): JsonResponse
    {
        $request->validate([
            'po_id' => 'required|uuid|exists:purchase_orders,id',
            'goods_receipt_id' => 'nullable|uuid|exists:goods_receipts,id',
            'handed_by_name' => 'required|string|max:255',
            'handed_by_position' => 'required|string|max:255',
            'received_by_name' => 'required|string|max:255',
            'received_by_position' => 'required|string|max:255',
            'witness_name' => 'nullable|string|max:255',
            'witness_position' => 'nullable|string|max:255',
            'items' => 'nullable|array',
            'handover_notes' => 'nullable|string',
        ]);

        try {
            Log::info('BastController.store called', [
                'po_id' => $request->input('po_id'),
                'user_id' => Auth::id(),
            ]);

            $po = PurchaseOrder::findOrFail($request->input('po_id'));
            $buyer = Company::query()->findOrFail($po->buyer_company_id);
            $vendor = $po->vendor_id ? Company::query()->find($po->vendor_id) : null;
            abort_unless($access->isMember($request->user(), $buyer) || ($vendor && $access->isMember($request->user(), $vendor)), 403);

            $bast = $action->execute($po, $request->all());

            Log::info('BAST created, dispatching event', [
                'bast_id' => $bast->id,
                'bast_number' => $bast->bast_number,
                'buyer_company_id' => $po->buyer_company_id,
            ]);

            // Dispatch event to notify buyer
            event(new BastIssuedEvent($bast, $po->buyer_company_id));

            Log::info('Event dispatched successfully', [
                'bast_id' => $bast->id,
            ]);

            return response()->json([
                'message' => 'BAST created successfully.',
                'bast' => $bast->load('purchaseOrder', 'buyerCompany', 'vendorCompany'),
            ], 201);
        } catch (\Exception $e) {
            Log::error('BastController.store error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    /**
     * Get BAST by ID
     */
    public function show(Request $request, string $id, CompanyAccessService $access): JsonResponse
    {
        $bast = Bast::with('purchaseOrder', 'buyerCompany', 'vendorCompany', 'handedByUser', 'receivedByUser', 'witnessUser')
            ->findOrFail($id);
        $this->assertCompanyAccess($request, $bast, $access);

        return response()->json(['bast' => $bast]);
    }

    /**
     * List BASTs for a company
     */
    public function index(Request $request, CompanyAccessService $access): JsonResponse
    {
        $companyId = $request->input('company_id');
        abort_unless($companyId, 422, 'company_id is required');
        $access->assertMember($request->user(), Company::query()->findOrFail($companyId));
        try {
            // Validate only required parameters without database checks first

            Log::info('BastController.index called', [
                'company_id' => $companyId,
            ]);

            // Try to get data
            $query = Bast::where(function ($q) use ($companyId) {
                $q->where('buyer_company_id', $companyId)
                  ->orWhere('vendor_company_id', $companyId);
            });

            if ($request->has('po_id')) {
                $query->where('po_id', $request->input('po_id'));
            }

            if ($request->has('status')) {
                $query->where('status', $request->input('status'));
            }

            $perPage = $request->input('per_page', 10);
            $basts = $query->with('purchaseOrder', 'buyerCompany', 'vendorCompany')->orderBy('created_at', 'desc')->paginate($perPage);

            Log::info('BastController.index result', [
                'company_id' => $companyId,
                'count' => $basts->total(),
            ]);

            return response()->json($basts);
        } catch (\PDOException $e) {
            // Table doesn't exist yet
            Log::warning('BAST table not found: ' . $e->getMessage());
            return response()->json([
                'data' => [],
                'current_page' => 1,
                'per_page' => 10,
                'total' => 0,
                'last_page' => 1,
                'message' => 'BAST feature is being initialized'
            ]);
        } catch (\Exception $e) {
            Log::error('BAST index error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'company_id' => $request->input('company_id')
            ]);
            return response()->json([
                'data' => [],
                'current_page' => 1,
                'per_page' => 10,
                'total' => 0,
                'last_page' => 1,
                'message' => 'BAST feature is being initialized'
            ]);
        }
    }

    /**
     * Sign BAST as handed-by party (vendor)
     */
    public function signHandedBy(Request $request, string $id, CompanyAccessService $access): JsonResponse
    {
        $request->validate([
            'handed_by_user_id' => 'required|uuid|exists:users,id',
            'handed_by_name' => 'required|string',
            'handed_by_position' => 'required|string',
        ]);

        $user = $request->user();
        
        // Fresh query to get latest roles from database
        $user->load('roles');
        
        // Allow multiple roles to sign
        $allowedRoles = ['super-admin', 'admin', 'manager', 'staff', 'vendor', 'buyer', 'finance'];
        $hasAllowedRole = $user->roles->whereIn('slug', $allowedRoles)->isNotEmpty();
        
        Log::info('signHandedBy role check', [
            'user_id' => $user->id,
            'roles' => $user->roles->pluck('slug')->toArray(),
            'has_allowed_role' => $hasAllowedRole,
        ]);
        
        if (!$hasAllowedRole) {
            return response()->json(['message' => 'Unauthorized to sign documents.'], 403);
        }

        $bast = Bast::findOrFail($id);
        abort_unless((string) $request->input('handed_by_user_id') === (string) $user->id, 403, 'Anda hanya dapat menandatangani atas nama akun sendiri.');
        $access->assertMember($user, Company::query()->findOrFail($bast->vendor_company_id));

        $bast->update([
            'handed_by_user_id' => $request->input('handed_by_user_id'),
            'handed_by_name' => $request->input('handed_by_name'),
            'handed_by_position' => $request->input('handed_by_position'),
            'handed_by_signed_at' => now(),
        ]);

        if ($bast->isFullySigned()) {
            $bast->markCompleted();
        }

        return response()->json([
            'message' => 'Signature recorded successfully.',
            'bast' => $bast,
        ]);
    }

    /**
     * Sign BAST as received-by party (buyer)
     */
    public function signReceivedBy(Request $request, string $id, CompanyAccessService $access): JsonResponse
    {
        $request->validate([
            'received_by_user_id' => 'required|uuid|exists:users,id',
            'received_by_name' => 'required|string',
            'received_by_position' => 'required|string',
        ]);

        $user = $request->user();
        
        // Fresh query to get latest roles from database
        $user->load('roles');
        
        // Allow multiple roles to sign
        $allowedRoles = ['super-admin', 'admin', 'manager', 'staff', 'vendor', 'buyer', 'finance'];
        $hasAllowedRole = $user->roles->whereIn('slug', $allowedRoles)->isNotEmpty();
        
        Log::info('signReceivedBy role check', [
            'user_id' => $user->id,
            'roles' => $user->roles->pluck('slug')->toArray(),
            'has_allowed_role' => $hasAllowedRole,
        ]);
        
        if (!$hasAllowedRole) {
            return response()->json(['message' => 'Unauthorized to sign documents.'], 403);
        }

        $bast = Bast::findOrFail($id);
        abort_unless((string) $request->input('received_by_user_id') === (string) $user->id, 403, 'Anda hanya dapat menandatangani atas nama akun sendiri.');
        $access->assertMember($user, Company::query()->findOrFail($bast->buyer_company_id));

        $bast->update([
            'received_by_user_id' => $request->input('received_by_user_id'),
            'received_by_name' => $request->input('received_by_name'),
            'received_by_position' => $request->input('received_by_position'),
            'received_by_signed_at' => now(),
        ]);

        if ($bast->isFullySigned()) {
            $bast->markCompleted();
        }

        return response()->json([
            'message' => 'Signature recorded successfully.',
            'bast' => $bast,
        ]);
    }

    /**
     * Sign BAST as witness
     */
    public function signWitness(Request $request, string $id, CompanyAccessService $access): JsonResponse
    {
        $request->validate([
            'witness_user_id' => 'required|uuid|exists:users,id',
            'witness_name' => 'required|string',
            'witness_position' => 'required|string',
        ]);

        $user = $request->user();
        
        // Fresh query to get latest roles from database
        $user->load('roles');
        
        // Check using collection instead of query builder to use loaded data
        $hasManager = $user->roles->contains('slug', 'manager');
        
        Log::info('signWitness role check', [
            'user_id' => $user->id,
            'roles' => $user->roles->pluck('slug')->toArray(),
            'has_manager' => $hasManager,
        ]);
        
        if (!$hasManager) {
            return response()->json(['message' => 'Only Manager can sign documents.'], 403);
        }

        $bast = Bast::findOrFail($id);
        abort_unless((string) $request->input('witness_user_id') === (string) $user->id, 403, 'Anda hanya dapat menandatangani atas nama akun sendiri.');
        $access->assertMember($user, Company::query()->findOrFail($bast->buyer_company_id));

        $bast->update([
            'witness_user_id' => $request->input('witness_user_id'),
            'witness_name' => $request->input('witness_name'),
            'witness_position' => $request->input('witness_position'),
            'witness_signed_at' => now(),
        ]);

        if ($bast->isFullySigned()) {
            $bast->markCompleted();
        }

        return response()->json([
            'message' => 'Witness signature recorded successfully.',
            'bast' => $bast,
        ]);
    }

    /**
     * Generate and display BAST PDF in browser (for printing)
     */
    public function showPdf(Request $request, string $id, BuildPurchaseOrderPaymentBreakdownAction $buildPurchaseOrderPaymentBreakdownAction, CompanyAccessService $access)
    {
        $bast = Bast::with('purchaseOrder', 'buyerCompany', 'vendorCompany')
            ->findOrFail($id);
        $this->assertCompanyAccess($request, $bast, $access);

        $disk = config('filesystems.default');
        /** @var FilesystemAdapter $storageDisk */
        $storageDisk = Storage::disk($disk);
        $buyerLogoUrl = null;
        if ($bast->buyerCompany && $bast->buyerCompany->logo_path) {
            $buyerLogoUrl = $this->getAssetUrl($storageDisk, $bast->buyerCompany->logo_path);
        }
        $vendorLogoUrl = null;
        if ($bast->vendorCompany && $bast->vendorCompany->logo_path) {
            $vendorLogoUrl = $this->getAssetUrl($storageDisk, $bast->vendorCompany->logo_path);
        }

        // Return view directly for browser display (user can Ctrl+P)
        return view('print.bast', [
            'bast' => $bast,
            'buyer_logo_url' => $buyerLogoUrl,
            'vendor_logo_url' => $vendorLogoUrl,
            'paymentBreakdown' => $buildPurchaseOrderPaymentBreakdownAction->execute(
                (float) collect($bast->items ?? [])->sum(fn ($item) => $item['total_amount'] ?? 0),
            ),
        ]);
    }

    /**
     * Helper to get asset URL with support for S3 temporary URLs
     */
    private function getAssetUrl(FilesystemAdapter $storage, string $path): string
    {
        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }

        $diskName = config('filesystems.default');
        if (in_array($diskName, ['s3', 'spaces', 'gcs', 'azure'])) {
            try {
                return $storage->temporaryUrl($path, now()->addHours(1));
            } catch (\Exception $e) {
                Log::warning('Failed to generate temporary URL, falling back to public URL', [
                    'error' => $e->getMessage(),
                    'path' => $path
                ]);
            }
        }

        return $storage->url($path);
    }

    private function assertCompanyAccess(Request $request, Bast $bast, CompanyAccessService $access): void
    {
        $buyer = $bast->buyer_company_id ? Company::query()->find($bast->buyer_company_id) : null;
        $vendor = $bast->vendor_company_id ? Company::query()->find($bast->vendor_company_id) : null;
        abort_unless(
            ($buyer && $access->isMember($request->user(), $buyer))
            || ($vendor && $access->isMember($request->user(), $vendor)),
            403,
            'Anda tidak memiliki akses ke dokumen ini.',
        );
    }
}
