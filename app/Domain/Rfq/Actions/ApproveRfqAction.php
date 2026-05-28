<?php

namespace App\Domain\Rfq\Actions;

use App\Domain\Rfq\Repositories\RfqRepositoryInterface;
use App\Domain\Rfq\Models\Rfq;
use App\Domain\Auth\Models\User;
use Illuminate\Validation\UnauthorizedException;

class ApproveRfqAction
{
    public function __construct(
        private readonly RfqRepositoryInterface $rfqRepository
    ) {}

    /**
     * Buyer Manager approves Purchase Request RFQ and lists it on Global RFQs.
     *
     * @param User $manager The approving manager user
     * @param Rfq $rfq The target RFQ
     * @return Rfq
     * @throws UnauthorizedException
     */
    public function execute(User $manager, Rfq $rfq): Rfq
    {
        if ($manager->role !== 'manager') {
            throw new UnauthorizedException("Only purchasing managers can approve RFQs.");
        }

        return $this->rfqRepository->update($rfq, ['status' => 'active']);
    }
}
