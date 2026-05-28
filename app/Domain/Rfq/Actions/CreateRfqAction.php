<?php

namespace App\Domain\Rfq\Actions;

use App\Domain\Rfq\Repositories\RfqRepositoryInterface;
use App\Domain\Company\Models\Company;
use App\Domain\Rfq\Models\Rfq;
use App\Domain\Communication\Actions\BroadcastWebsocketNotificationAction;

class CreateRfqAction
{
    public function __construct(
        private readonly RfqRepositoryInterface $rfqRepository,
        private readonly BroadcastWebsocketNotificationAction $broadcastAction
    ) {}

    /**
     * Create a draft RFQ and checkout cart items to request manager PO approval.
     *
     * @param Company $buyerCompany The buyer's company
     * @param string $title RFQ Title
     * @param string|null $description RFQ Description
     * @param array $cartItems Array of items: ['catalogue_id' => X, 'qty' => Y, 'expected_date' => Z]
     * @return Rfq
     */
    public function execute(Company $buyerCompany, string $title, ?string $description, array $cartItems, ?int $userId = null, string $status = 'pending_approval'): Rfq
    {
        $rfq = $this->rfqRepository->create([
            'company_id'  => $buyerCompany->id,
            'user_id'     => $userId,
            'title'       => $title,
            'description' => $description,
            'status'      => $status,
        ]);

        $lineItems = array_map(fn($item) => [
            'rfq_id'        => $rfq->id,
            'catalogue_id'  => $item['catalogue_id'],
            'qty'           => $item['qty'],
            'expected_date' => $item['expected_date'] ?? null,
        ], $cartItems);

        $this->rfqRepository->createItems($lineItems);

        $this->broadcastAction->execute(
            "New PR Created",
            "PR '{$title}' has been submitted and is pending approval.",
            'test-channel',
            true,
            $userId,
            "/my-pr"
        );

        return $rfq;
    }
}
