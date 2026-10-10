<?php

namespace App\Domain\Rfq\Actions;

use App\Domain\AI\Services\DemoBotService;
use App\Domain\Communication\Actions\BroadcastWebsocketNotificationAction;
use App\Domain\Communication\Notifications\DatabaseNotification;
use App\Domain\Company\Models\Company;
use App\Domain\Rfq\Models\Rfq;
use App\Domain\Rfq\Repositories\RfqRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CreateRfqAction
{
    public function __construct(
        private readonly RfqRepositoryInterface $rfqRepository,
        private readonly BroadcastWebsocketNotificationAction $broadcastAction,
        private readonly NotifyRelevantVendorsAction $notifyVendorsAction
    ) {}

    /**
     * Create a draft RFQ and checkout cart items to request manager PO approval.
     *
     * @param  Company  $buyerCompany  The buyer's company
     * @param  string  $title  RFQ Title
     * @param  string|null  $description  RFQ Description
     * @param  array  $cartItems  Array of items: ['catalogue_id' => X, 'qty' => Y, 'expected_date' => Z]
     */
    public function execute(Company $buyerCompany, string $title, ?string $description, array $cartItems, ?string $userId = null, string $status = 'pending_approval', ?int $durationDays = null, ?string $documentPath = null, ?string $deliveryPoint = null, ?string $department = null, ?string $warehouseId = null, string $procurementMode = 'tender'): Rfq
    {
        // Debug: Log jumlah item yang akan diproses
        Log::info('DEBUG: CreateRfqAction - Cart items processing', [
            'total_cart_items' => count($cartItems),
            'cart_items_details' => $cartItems,
        ]);

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
            'duration_days' => $durationDays ?? 7,
            'delivery_point' => $deliveryPoint,
            'department' => $department,
            'warehouse_id' => $warehouseId,
        ]);

        $lineItems = array_map(fn ($item) => [
            'rfq_id' => $rfq->id,
            'catalogue_id' => $item['catalogue_id'] ?? null,
            'item_name' => $item['item_name'] ?? null,
            'sku' => $item['sku'] ?? null,
            'uom' => $item['uom'] ?? null,
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
