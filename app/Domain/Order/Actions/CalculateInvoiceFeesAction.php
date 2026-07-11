<?php

namespace App\Domain\Order\Actions;

class CalculateInvoiceFeesAction
{
    /**
     * Hitung biaya layanan dan PPN sesuai struktur:
     *
     * - Platform fee : 3% dari base amount
     * - Admin Bank   : Rp 4.400 (flat)
     * - PPN eComm    : 8% dari (platform fee + admin bank)
     * - Biaya Layanan: platform fee + admin bank + PPN eComm
     * - PPN          : 11% dari base amount (DPP)
     * - Total        : base amount + biaya layanan + PPN
     */
    public function execute(float $baseAmount): array
    {
        // Platform fee: 3% dari total pembelian sebelum PPN
        $platformFee = $baseAmount * 0.03;

        // Admin Bank (flat)
        $midtransFee = 4400;

        // PPN eComm: 8% dari (platform fee + admin bank)
        $ppnEcomm = ($platformFee + $midtransFee) * 0.08;

        // Total biaya layanan
        $serviceTotal = $platformFee + $midtransFee + $ppnEcomm;

        // PPN 11% dari base amount (DPP)
        $ppnFee = $baseAmount * 0.11;

        $totalAmount = $baseAmount + $serviceTotal + $ppnFee;

        return [
            'base_amount'   => $baseAmount,
            'platform_fee'  => $platformFee,
            'midtrans_fee'  => $midtransFee,
            'ppn_ecomm'     => $ppnEcomm,
            'service_total' => $serviceTotal,
            'ppn_fee'       => $ppnFee,
            'total_amount'  => $totalAmount,
        ];
    }
}
