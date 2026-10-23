<?php

namespace App\Domain\Crm\Services;

use App\Domain\Crm\Jobs\SendCrmCampaignRecipient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Queues due tenant campaigns exactly once, then delegates delivery to the queue. */
class CrmCampaignDispatchService
{
    public function dispatchDue(): int
    {
        $queuedRecipients = 0;
        $campaigns = DB::table('crm_campaigns')
            ->where('status', 'scheduled')
            ->where('scheduled_at', '<=', now())
            ->get();

        foreach ($campaigns as $campaign) {
            $queuedRecipients += DB::transaction(function () use ($campaign) {
                $lockedCampaign = DB::table('crm_campaigns')
                    ->where('id', $campaign->id)
                    ->lockForUpdate()
                    ->first();
                if (! $lockedCampaign || $lockedCampaign->status !== 'scheduled') {
                    return 0;
                }

                DB::table('crm_campaigns')->where('id', $lockedCampaign->id)->update([
                    'status' => 'sending',
                    'updated_at' => now(),
                ]);
                $audience = json_decode($lockedCampaign->audience ?? '{}', true) ?: [];
                $customers = DB::table('crm_customers')
                    ->where('company_id', $lockedCampaign->company_id)
                    ->where('user_id', $lockedCampaign->user_id)
                    ->whereNotNull('email')
                    ->when(
                        $audience['lifecycle_stage'] ?? null,
                        fn ($query, $stage) => $query->where('lifecycle_stage', $stage),
                    )
                    ->get(['id', 'email']);

                foreach ($customers as $customer) {
                    $recipientId = (string) Str::uuid();
                    DB::table('crm_campaign_recipients')->insert([
                        'id' => $recipientId,
                        'company_id' => $lockedCampaign->company_id,
                        'user_id' => $lockedCampaign->user_id,
                        'campaign_id' => $lockedCampaign->id,
                        'customer_id' => $customer->id,
                        'email' => $customer->email,
                        'status' => 'queued',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    SendCrmCampaignRecipient::dispatch($recipientId);
                }

                if ($customers->isEmpty()) {
                    DB::table('crm_campaigns')->where('id', $lockedCampaign->id)->update([
                        'status' => 'sent',
                        'sent_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                return $customers->count();
            });
        }

        return $queuedRecipients;
    }
}
