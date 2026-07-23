<?php

namespace App\Domain\Order\Actions;

use App\Domain\Order\Models\Bast;
use App\Domain\Order\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CreateBastAction
{
    /**
     * Create BAST (Berita Acara Serah Terima) for a purchase order
     *
     * @param PurchaseOrder $po Purchase order
     * @param array $data BAST details
     * @return Bast
     */
    public function execute(PurchaseOrder $po, array $data): Bast
    {

        if (empty($data['handed_by_name'])) {
            throw ValidationException::withMessages([
                'handed_by_name' => ['Handed by name is required.'],
            ]);
        }

        if (empty($data['received_by_name'])) {
            throw ValidationException::withMessages([
                'received_by_name' => ['Received by name is required.'],
            ]);
        }

        return DB::transaction(function () use ($po, $data) {
            $bast = new Bast([
                'po_id' => $po->id,
                'goods_receipt_id' => $data['goods_receipt_id'] ?? null,
                'buyer_company_id' => $po->buyer_company_id,
                'vendor_company_id' => $po->vendor_id,
                'bast_number' => Bast::generateBastNumber(),
                'bast_date' => $data['bast_date'] ?? now()->format('Y-m-d'),
                'status' => $data['status'] ?? 'draft',
                'items' => $data['items'] ?? [],
                'handed_by_name' => $data['handed_by_name'],
                'handed_by_position' => $data['handed_by_position'] ?? null,
                'handed_by_user_id' => $data['handed_by_user_id'] ?? auth()->id(),
                'received_by_name' => $data['received_by_name'],
                'received_by_position' => $data['received_by_position'] ?? null,
                'received_by_user_id' => $data['received_by_user_id'] ?? null,
                'witness_name' => $data['witness_name'] ?? null,
                'witness_position' => $data['witness_position'] ?? null,
                'witness_user_id' => $data['witness_user_id'] ?? null,
                'handover_notes' => $data['handover_notes'] ?? null,
                'witness_notes' => $data['witness_notes'] ?? null,
                'created_by' => $data['created_by'] ?? auth()->id(),
            ]);

            $bast->save();

            Log::info('BAST created successfully', [
                'bast_id' => $bast->id,
                'bast_number' => $bast->bast_number,
                'po_id' => $po->id,
                'buyer_company_id' => $po->buyer_company_id,
                'created_by' => $bast->created_by,
            ]);

            return $bast;
        });
    }
}
