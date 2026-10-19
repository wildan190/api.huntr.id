<?php

namespace App\Domain\EFaktur\Actions;

use App\Domain\EFaktur\Models\EFaktur;
use App\Domain\Order\Models\PurchaseOrder;
use App\Domain\Order\Models\Bast;
use App\Domain\Subscription\Services\TaxQuotaService;

class IssueEFakturWithQuotaAction
{
    public function __construct(private CreateEFakturAction $create, private TaxQuotaService $quota) {}

    public function execute(Bast $bast, PurchaseOrder $po, array $extra = []): EFaktur
    {
        $reservation = $this->quota->reserve((string) $po->buyer_company_id, (string) $bast->id);
        try {
            $efaktur = $this->create->execute($bast, $po, $extra);
        } catch (\Throwable $e) {
            // The provider may have accepted the request before a timeout.
            $this->quota->finish($reservation, null);
            throw $e;
        }
        $this->quota->finish($reservation, (string) $efaktur->id);
        return $efaktur;
    }
}
