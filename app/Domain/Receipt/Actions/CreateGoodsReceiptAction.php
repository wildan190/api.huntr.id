<?php

namespace App\Domain\Receipt\Actions;

use App\Domain\Order\Repositories\OrderRepositoryInterface;
use App\Domain\Receipt\Repositories\ReceiptRepositoryInterface;
use App\Domain\Order\Models\DeliveryOrder;
use App\Domain\Receipt\Models\GoodsReceipt;
use Illuminate\Validation\ValidationException;

class CreateGoodsReceiptAction
{
    public function __construct(
        private readonly ReceiptRepositoryInterface $receiptRepository,
        private readonly OrderRepositoryInterface   $orderRepository
    ) {}

    /**
     * Buyer company performs goods receipt, releasing handover document and releasing final invoice.
     *
     * @param DeliveryOrder $do Target DO
     * @param array $data Input fields: received_qty, handover_document_path
     * @return GoodsReceipt
     * @throws ValidationException
     */
    public function execute(DeliveryOrder $do, array $data): GoodsReceipt
    {
        if ($do->status !== 'delivered') {
            throw ValidationException::withMessages([
                'do' => ['You must confirm DO delivery before performing Goods Receipt.'],
            ]);
        }

        // 1. Create Goods Receipt
        $receipt = $this->receiptRepository->createGoodsReceipt([
            'delivery_order_id'     => $do->id,
            'received_qty'          => $data['received_qty'],
            'handover_document_path'=> $data['handover_document_path'] ?? 'handover_docs/default_handover.pdf',
            'status'                => 'completed',
        ]);

        // 2. Update DO and PO statuses
        $this->orderRepository->updateDeliveryOrder($do, ['status' => 'received']);
        $po = $do->purchaseOrder;
        $this->orderRepository->updatePurchaseOrder($po, ['status' => 'completed']);

        // 3. Derive amount from accepted proposal and release Final Invoice
        $winningProposal = $this->orderRepository->findAcceptedProposal($po);
        $poAmount        = $winningProposal ? $winningProposal->price_offer : 0;

        $this->orderRepository->createInvoice([
            'purchase_order_id' => $po->id,
            'type'              => 'final',
            'amount'            => $poAmount,
            'status'            => 'pending_finance',
        ]);

        return $receipt;
    }
}
