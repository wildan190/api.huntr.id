<?php

namespace App\Domain\Receipt\Actions;

use App\Domain\Order\Repositories\OrderRepositoryInterface;
use App\Domain\Company\Models\Company;
use App\Domain\Order\Models\DeliveryOrder;
use Illuminate\Validation\ValidationException;

class ConfirmDeliveryOrderAction
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository
    ) {}

    /**
     * Buyer company confirms delivery of the DO.
     *
     * @param Company $buyerCompany Target buyer company
     * @param DeliveryOrder $do Target DO
     * @return DeliveryOrder
     * @throws ValidationException
     */
    public function execute(Company $buyerCompany, DeliveryOrder $do): DeliveryOrder
    {
        $po = $do->purchaseOrder;

        if ($po->rfq->company_id !== $buyerCompany->id) {
            throw ValidationException::withMessages([
                'buyer' => ['This delivery order does not belong to your company purchase order.'],
            ]);
        }

        return $this->orderRepository->updateDeliveryOrder($do, ['status' => 'delivered']);
    }
}
