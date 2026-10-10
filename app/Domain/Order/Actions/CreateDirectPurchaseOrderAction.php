<?php

namespace App\Domain\Order\Actions;

use App\Domain\Auth\Models\User;
use App\Domain\Order\Models\PurchaseOrder;
use App\Domain\Rfq\Models\Rfq;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\UnauthorizedException;

class CreateDirectPurchaseOrderAction
{
    public function execute(User $actor, Rfq $rfq, array $data): PurchaseOrder
    {
        if ((string) $rfq->company_id !== (string) $data['company_id']) {
            abort(422, 'The PR does not belong to this buyer workspace.');
        }

        $isOwner = (string) $rfq->company->owner_id === (string) $actor->id;
        if (! $isOwner && ! $actor->hasRole('manager')) {
            throw new UnauthorizedException('Only a purchasing manager or company owner can create a direct PO.');
        }

        return DB::transaction(function () use ($rfq, $data, $actor) {
            $lockedRfq = Rfq::query()->lockForUpdate()->findOrFail($rfq->id);

            abort_unless($lockedRfq->procurement_mode === 'direct', 422, 'Only direct purchase PRs can create a custom PO.');
            abort_unless($lockedRfq->status === 'approved', 422, 'Approve this PR before creating a PO.');
            abort_if(PurchaseOrder::where('rfq_id', $lockedRfq->id)->exists(), 422, 'A PO has already been created from this PR.');

            $items = $lockedRfq->items;
            abort_if($items->isEmpty(), 422, 'A direct PO requires at least one approved PR item.');

            $total = $items->sum(fn ($item) => (float) ($item->estimated_price ?? 0) * (float) $item->qty);
            $reference = strtoupper(substr(str_replace('-', '', $lockedRfq->id), -6));

            return PurchaseOrder::create([
                'buyer_company_id' => $lockedRfq->company_id,
                'rfq_id' => $lockedRfq->id,
                'vendor_id' => null,
                'vendor_name' => $data['vendor_name'],
                'po_number' => 'PO-'.now()->format('Ymd').'-'.$reference,
                'status' => 'issued',
                'created_by' => $actor->id,
                'approved_by' => $lockedRfq->approved_by,
                'total_amount' => $total,
                'currency' => strtoupper($data['currency'] ?? 'IDR'),
                'purchase_category' => $data['purchase_category'] ?? 'Direct purchase',
                'purchase_type' => $data['purchase_type'] ?? 'Immediate',
                'order_date' => today(),
                'expected_receiving_date' => $data['expected_receiving_date'] ?? null,
                'delivery_point' => $lockedRfq->delivery_point,
                'department' => $lockedRfq->department ?? 'General Procurement',
            ]);
        });
    }
}
