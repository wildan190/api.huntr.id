<?php

namespace App\Domain\Order\Actions;

use App\Domain\Admin\Models\AdminSetting;

class CalculateInvoiceFeesAction
{
    /**
     * Calculate the PO payment components after platform and service fees
     * have been removed from the subscription model.
     */
    public function execute(float $baseAmount): array
    {
        $adminBank = 4_400;
        $ppnRate = (float) AdminSetting::get('subscription_ppn_percent', 11) / 100;
        $pph23Rate = (float) AdminSetting::get('subscription_pph23_percent', 2) / 100;
        $ppnFee = round($baseAmount * $ppnRate, 2);
        $pph23 = round($baseAmount * $pph23Rate, 2);

        return [
            'base_amount' => $baseAmount,
            'platform_fee' => 0,
            'ppn_platform' => 0,
            'midtrans_fee' => $adminBank,
            'pph23' => $pph23,
            'service_total' => 0,
            'ppn_fee' => $ppnFee,
            'total_amount' => round($baseAmount + $adminBank + $ppnFee - $pph23, 2),
        ];
    }
}
