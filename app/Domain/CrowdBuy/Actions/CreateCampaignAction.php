<?php

namespace App\Domain\CrowdBuy\Actions;

use App\Domain\Auth\Models\User;
use App\Domain\Company\Models\Company;
use App\Domain\CrowdBuy\Models\CrowdBuyCampaign;
use App\Domain\CrowdBuy\Repositories\CrowdBuyRepositoryInterface;
use Illuminate\Support\Str;

class CreateCampaignAction
{
    public function __construct(
        protected CrowdBuyRepositoryInterface $repository
    ) {}

    public function execute(User $user, array $data): CrowdBuyCampaign
    {
        $supplierCompanyId = $data['supplier_company_id'] ?? $user->company_id;

        // Verify supplier eligibility: must be approved vendor
        $company = Company::findOrFail($supplierCompanyId);
        if ($company->type !== 'vendor' || $company->status !== 'approved') {
            throw new \DomainException('Hanya supplier terverifikasi (status approved) yang dapat membuat campaign Crowd Buy.');
        }

        // Generate unique campaign code
        $dateStr = now()->format('Ymd');
        $random = strtoupper(Str::random(5));
        $code = "CBC-{$dateStr}-{$random}";

        $campaignData = array_merge($data, [
            'code' => $code,
            'supplier_company_id' => $supplierCompanyId,
            'created_by' => $user->id,
            'status' => 'draft',
            'current_quantity' => 0,
        ]);

        $campaign = $this->repository->createCampaign($campaignData);

        $this->repository->recordHistory(
            $campaign,
            $user->id,
            null,
            'draft',
            'Draft campaign created'
        );

        return $campaign;
    }
}
