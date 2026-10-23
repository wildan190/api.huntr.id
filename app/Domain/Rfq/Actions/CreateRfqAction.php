<?php

namespace App\Domain\Rfq\Actions;

use App\Domain\AI\Services\DemoBotService;
use App\Domain\Communication\Actions\BroadcastWebsocketNotificationAction;
use App\Domain\Communication\Notifications\DatabaseNotification;
use App\Domain\Company\Models\Company;
use App\Domain\Company\Services\CompanyAccessService;
use App\Domain\Rfq\Models\Rfq;
use App\Domain\Rfq\Repositories\RfqRepositoryInterface;
use App\Domain\Subscription\Services\SubscriptionAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CreateRfqAction
{
    public function __construct(
        private readonly RfqRepositoryInterface $rfqRepository,
        private readonly BroadcastWebsocketNotificationAction $broadcastAction,
        private readonly NotifyRelevantVendorsAction $notifyVendorsAction,
        private readonly SubscriptionAccessService $subscriptionAccess,
        private readonly CompanyAccessService $companyAccess,
    ) {}

    /**
     * Create a draft RFQ and checkout cart items to request manager PO approval.
     *
     * @param  Company  $buyerCompany  The buyer's company
     * @param  string  $title  RFQ Title
     * @param  string|null  $description  RFQ Description
     * @param  array  $cartItems  Array of items: ['catalogue_id' => X, 'qty' => Y, 'expected_date' => Z]
     */
    public function execute(Company $buyerCompany, string $title, ?string $description, array $cartItems, ?string $userId = null, string $status = 'pending_approval', ?int $durationDays = null, ?string $documentPath = null, ?string $deliveryPoint = null, ?string $department = null, ?string $warehouseId = null, string $procurementMode = 'tender', string $procurementType = 'goods', ?string $serviceStartDate = null, ?string $serviceEndDate = null, ?string $serviceLocation = null, ?string $acceptanceCriteria = null): Rfq
    {
        $this->subscriptionAccess->assertAvailable($buyerCompany);

        // Debug: Log jumlah item yang akan diproses
        Log::info('DEBUG: CreateRfqAction - Cart items processing', [
            'total_cart_items' => count($cartItems),
            'cart_items_details' => $cartItems,
        ]);

        if ($procurementType === 'services' && $warehouseId) {
            abort(422, 'Layanan tidak dapat menggunakan tujuan warehouse. Gunakan lokasi layanan.');
        }
        if ($warehouseId) {
            abort_unless(DB::table('company_apps')->where('company_id', $buyerCompany->id)->where('app_key', 'wms-inventory')->whereNotNull('installed_at')->exists(), 422, 'Install WMS & Inventory before choosing a warehouse destination.');
            abort_unless(DB::table('warehouses')->where('id', $warehouseId)->where('company_id', $buyerCompany->id)->where('status', 'active')->exists(), 422, 'Warehouse tujuan tidak valid atau tidak aktif.');
        }
        $rfq = $this->rfqRepository->create([
            'company_id' => $buyerCompany->id,
            'user_id' => $userId,
            'title' => $title,
            'description' => $description,
            'document_path' => $documentPath,
            'status' => $status,
            'procurement_mode' => $procurementMode,
            'procurement_type' => $procurementType,
            'service_start_date' => $serviceStartDate,
            'service_end_date' => $serviceEndDate,
            'service_location' => $serviceLocation,
            'acceptance_criteria' => $acceptanceCriteria,
            'duration_days' => $durationDays ?? 7,
            'delivery_point' => $deliveryPoint,
            'department' => $department,
            'warehouse_id' => $warehouseId,
        ]);

        $lineItems = array_map(fn ($item) => [
            'rfq_id' => $rfq->id,
            'catalogue_id' => $item['catalogue_id'] ?? null,
            'line_type' => $item['line_type'] ?? (($item['catalogue_id'] ?? null) ? 'goods' : 'service'),
            'item_name' => $item['item_name'] ?? null,
            'sku' => $item['sku'] ?? null,
            'uom' => $item['uom'] ?? null,
            'service_scope' => $item['service_scope'] ?? null,
            'acceptance_criteria' => $item['acceptance_criteria'] ?? null,
            'service_start_date' => $item['service_start_date'] ?? $serviceStartDate,
            'service_end_date' => $item['service_end_date'] ?? $serviceEndDate,
            'qty' => $item['qty'],
            'estimated_price' => $item['estimated_price'] ?? null,
            'expected_date' => $item['expected_date'] ?? null,
        ], $cartItems);

        // Debug: Log line items yang akan dibuat
        Log::info('DEBUG: CreateRfqAction - Line items to create', [
            'total_line_items' => count($lineItems),
            'line_items_details' => $lineItems,
        ]);

        $this->rfqRepository->createItems($lineItems);

        if ($status === 'pending_approval') {
            $estimatedAmount = collect($lineItems)->sum(fn (array $item) => (float) ($item['qty'] ?? 0) * (float) ($item['estimated_price'] ?? 0));
            $this->companyAccess->bootstrap($buyerCompany);
            $this->companyAccess->startSubject($buyerCompany, 'purchase_request', $rfq->id, $estimatedAmount, $userId, ['title' => $rfq->title, 'procurement_mode' => $procurementMode, 'procurement_type' => $procurementType]);
        }

        $this->broadcastAction->execute(
            'New PR Created',
            "PR '{$title}' has been submitted and is pending approval.",
            'test-channel',
            true,
            $userId,
            '/my-pr',
            ['type' => 'pr_created']
        );

        $managers = $buyerCompany->approvers();
        foreach ($managers as $manager) {
            $this->broadcastAction->execute(
                'PR Requires Approval',
                "PR '{$title}' has been submitted and requires your approval.",
                'test-channel',
                true,
                $manager->id,
                '/approvals',
                ['type' => 'pending_approval']
            );
        }

        $buyerCompany->notify(new DatabaseNotification(
            'PR Requires Approval',
            "PR '{$title}' has been submitted and requires your approval.",
            '/approvals',
            null,
            ['type' => 'pending_approval', 'rfq_id' => $rfq->id]
        ));

        if ($status === 'active') {
            $this->notifyVendorsAction->execute($rfq);
        }

        // Demo Mode: Trigger 5 AI Vendor Bots if active or demo mode
        if (config('app.demo_mode', false) && ($status === 'active' || $status === 'pending_approval')) {
            try {
                app(DemoBotService::class)->generateFiveVendorBotsForRfq($rfq);
            } catch (\Exception $e) {
                Log::warning('DemoBotService auto-trigger failed: '.$e->getMessage());
            }
        }

        return $rfq;
    }
}
