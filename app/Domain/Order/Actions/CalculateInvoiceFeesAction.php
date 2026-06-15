<?php

namespace App\Domain\Order\Actions;

class CalculateInvoiceFeesAction
{
    public function execute(float $baseAmount): array
    {
        // Calculate Platform Fee
        if ($baseAmount <= 50000000) { // 0-50jt
            $platformFee = $baseAmount * 0.025;
        } elseif ($baseAmount <= 250000000) { // 51-250jt
            $platformFee = $baseAmount * 0.02;
        } else { // 251jt and above
            $platformFee = $baseAmount * 0.01;
        }

        $midtransFee = 4400;

        // Calculate PPN 11% on (platformFee + midtransFee)
        $ppnFee = ($platformFee + $midtransFee) * 0.11;

        $totalAmount = $baseAmount + $platformFee + $midtransFee + $ppnFee;

        return [
            'base_amount' => $baseAmount,
            'platform_fee' => $platformFee,
            'midtrans_fee' => $midtransFee,
            'ppn_fee' => $ppnFee,
            'total_amount' => $totalAmount
        ];
    }
}
