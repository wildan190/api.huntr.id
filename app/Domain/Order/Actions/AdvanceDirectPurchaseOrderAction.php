<?php

namespace App\Domain\Order\Actions;

use App\Domain\Auth\Models\User;
use App\Domain\Order\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\UnauthorizedException;

class AdvanceDirectPurchaseOrderAction
{
    private const NEXT_STATUS = [
        'issued' => 'confirmed',
        'confirmed' => 'in_transit',
        'in_transit' => 'delivered',
    ];

    public function execute(User $actor, PurchaseOrder $po, string $companyId): PurchaseOrder
    {
        $isOwner = (string) $po->buyer?->owner_id === (string) $actor->id;
        if (! $isOwner && ! $actor->hasRole('manager')) {
            throw new UnauthorizedException('Only a purchasing manager or company owner can update a direct PO.');
        }

        abort_unless((string) $po->buyer_company_id === (string) $companyId, 422, 'This PO belongs to another buyer workspace.');

        return DB::transaction(function () use ($po, $actor) {
            $lockedPo = PurchaseOrder::query()->lockForUpdate()->with('rfq')->findOrFail($po->id);
            abort_unless($lockedPo->rfq?->procurement_mode === 'direct', 422, 'This action is only available for direct POs.');

            $nextStatus = self::NEXT_STATUS[$lockedPo->status] ?? null;
            abort_unless($nextStatus, 422, 'This direct PO has no further operational step.');

            $timeline = $lockedPo->tracking_timeline ?? [];
            $timeline[] = [
                'status' => $nextStatus,
                'timestamp' => now()->toIso8601String(),
                'actor_name' => $actor->name,
                'actor_type' => 'buyer',
                'note' => match ($nextStatus) {
                    'confirmed' => 'Vendor confirmation recorded by buyer.',
                    'in_transit' => 'Goods dispatch recorded by buyer.',
                    'delivered' => 'Goods receipt recorded by buyer.',
                },
            ];

            $lockedPo->update([
                'status' => $nextStatus,
                'tracking_timeline' => $timeline,
            ]);

            return $lockedPo->fresh();
        });
    }
}
