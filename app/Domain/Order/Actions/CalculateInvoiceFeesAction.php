<?php

namespace App\Domain\Order\Actions;

use App\Domain\Company\Models\Company;

class CalculateInvoiceFeesAction
{
    /**
     * Hitung biaya layanan dan PPN sesuai struktur baru:
     *
     * - Platform fee : tier-based dari base amount (gratis jika dalam masa trial 30 hari)
     *     0 - 100.000.000           → 5%
     *     100.000.001 - 250.000.000 → 3%
     *     250.000.001 ke atas       → 2%
     * - PPN Platform  : 11% dari platform fee
     * - Admin Bank    : Rp 4.400 (flat)
     * - PPH 23        : 2% dari platform fee
     * - Biaya Layanan : (platform fee + PPN platform) + admin bank - PPH 23
     * - PPN Barang    : 11% dari base amount (DPP)
     * - Total         : base amount + biaya layanan + PPN barang
     */
    public function execute(
        float $baseAmount,
        ?Company $buyerCompany = null,
        bool $waivePlatformFee = false,
    ): array
    {
        $isTrial = false;
        if ($buyerCompany && $buyerCompany->created_at) {
            $isTrial = $buyerCompany->created_at->addDays(30)->isAfter(now());
        }

        // Platform fee: tier-based dari total pembelian sebelum PPN (gratis jika trial)
        $platformFee = 0;
        if (!$isTrial && !$waivePlatformFee) {
            $platformFeeRate = $this->getPlatformFeeRate($baseAmount);
            $platformFee     = $baseAmount * $platformFeeRate;
        }

        // PPN Platform: 11% dari platform fee
        $ppnPlatform = $platformFee * 0.11;

        // Admin Bank (flat)
        $adminBank = 4400;

        // PPH 23: 2% dari platform fee
        $pph23 = $platformFee * 0.02;

        // Total biaya layanan: (platform fee + PPN platform) + admin bank - PPH 23
        $serviceTotal = ($platformFee + $ppnPlatform) + $adminBank - $pph23;

        // PPN 11% dari base amount (DPP)
        $ppnFee = $baseAmount * 0.11;

        $totalAmount = $baseAmount + $serviceTotal + $ppnFee;

        return [
            'base_amount'   => $baseAmount,
            'platform_fee'  => $platformFee,
            'ppn_platform'  => $ppnPlatform,
            'midtrans_fee'  => $adminBank,
            'pph23'         => $pph23,
            'service_total' => $serviceTotal,
            'ppn_fee'       => $ppnFee,
            'total_amount'  => $totalAmount,
        ];
    }

    /**
     * Tentukan rate platform fee berdasarkan tier nilai transaksi.
     */
    private function getPlatformFeeRate(float $amount): float
    {
        if ($amount <= 100_000_000) {
            return 0.05; // 5%
        } elseif ($amount <= 250_000_000) {
            return 0.03; // 3%
        } else {
            return 0.02; // 2%
        }
    }
}
