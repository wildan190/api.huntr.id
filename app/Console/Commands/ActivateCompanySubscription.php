<?php

namespace App\Console\Commands;

use App\Domain\Company\Models\Company;
use App\Domain\Subscription\Actions\ActivateCompanySubscriptionAction;
use Illuminate\Console\Command;
use Throwable;

class ActivateCompanySubscription extends Command
{
    protected $signature = 'subscription:activate
        {company_id : UUID perusahaan buyer}
        {gmv_limit : Kuota GMV tahunan dalam Rupiah}
        {--strategy=transaction_fee : transaction_fee atau renewal_required}
        {--force : Lewati konfirmasi interaktif}';

    protected $description = 'Aktifkan subscription GMV tahunan setelah pembayaran upfront 1,5% telah tervalidasi.';

    public function handle(ActivateCompanySubscriptionAction $action): int
    {
        $company = Company::find($this->argument('company_id'));
        if (! $company) {
            $this->error('Perusahaan tidak ditemukan.');
            return self::FAILURE;
        }

        $gmvLimit = (float) $this->argument('gmv_limit');
        $upfrontFee = round($gmvLimit * 0.015, 2);

        if (! $this->option('force') && ! $this->confirm(
            'Pembayaran upfront Rp ' . number_format($upfrontFee, 2, ',', '.') . ' sudah tervalidasi?'
        )) {
            $this->warn('Aktivasi dibatalkan.');
            return self::FAILURE;
        }

        try {
            $subscription = $action->execute($company, $gmvLimit, (string) $this->option('strategy'));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }

        $this->info('Subscription berhasil diaktifkan.');
        $this->table(['Subscription', 'Upfront 1,5%', 'GMV Limit', 'Berlaku sampai'], [[
            $subscription->id,
            'Rp ' . number_format((float) $subscription->upfront_fee, 2, ',', '.'),
            'Rp ' . number_format((float) $subscription->gmv_limit, 2, ',', '.'),
            $subscription->ends_at->format('d M Y'),
        ]]);

        return self::SUCCESS;
    }
}
