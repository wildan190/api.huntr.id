<?php

namespace App\Domain\Order\Listeners;

use App\Domain\Order\Events\BastIssuedEvent;
use App\Domain\Order\Notifications\BastIssuedNotification;
use App\Domain\Company\Models\Company;
use Illuminate\Support\Facades\Log;

class SendBastIssuedNotification
{
    public function handle(BastIssuedEvent $event): void
    {
        try {
            // Get buyer company
            $buyerCompany = Company::find($event->buyerCompanyId);
            if (!$buyerCompany) {
                Log::warning('Buyer company not found for BAST notification', [
                    'buyer_company_id' => $event->buyerCompanyId,
                    'bast_id' => $event->bast->id,
                ]);
                return;
            }

            // Send notification directly to company (not to individual users)
            // This allows any user who logs in to this company to see the notification
            $buyerCompany->notify(new BastIssuedNotification($event->bast));

            Log::info('BAST issued notification sent to buyer company', [
                'bast_id' => $event->bast->id,
                'bast_number' => $event->bast->bast_number,
                'buyer_company_id' => $event->buyerCompanyId,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send BAST issued notification', [
                'bast_id' => $event->bast->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
