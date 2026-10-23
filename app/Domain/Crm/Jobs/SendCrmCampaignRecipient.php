<?php

namespace App\Domain\Crm\Jobs;

use App\Domain\Crm\Mail\CrmCampaignMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/** Delivers an already isolated campaign recipient and closes only its own campaign. */
class SendCrmCampaignRecipient implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $recipientId) {}

    public function handle(): void
    {
        $recipient = DB::table('crm_campaign_recipients')->where('id', $this->recipientId)->first();
        if (! $recipient || $recipient->status !== 'queued' || ! $recipient->email) return;

        $campaign = DB::table('crm_campaigns')
            ->where('id', $recipient->campaign_id)
            ->where('company_id', $recipient->company_id)
            ->where('user_id', $recipient->user_id)
            ->where('status', 'sending')
            ->first();
        if (! $campaign) return;

        Mail::to($recipient->email)->send(new CrmCampaignMail($campaign->subject, $campaign->body));
        DB::table('crm_campaign_recipients')->where('id', $recipient->id)->update([
            'status' => 'sent',
            'sent_at' => now(),
            'updated_at' => now(),
        ]);
        $this->completeCampaign($recipient->campaign_id, $recipient->company_id, $recipient->user_id);
    }

    public function failed(\Throwable $exception): void
    {
        $recipient = DB::table('crm_campaign_recipients')->where('id', $this->recipientId)->first();
        DB::table('crm_campaign_recipients')->where('id', $this->recipientId)->update([
            'status' => 'failed',
            'updated_at' => now(),
        ]);
        if ($recipient) $this->completeCampaign($recipient->campaign_id, $recipient->company_id, $recipient->user_id);
    }

    private function completeCampaign(string $campaignId, string $companyId, string $userId): void
    {
        $hasQueuedRecipient = DB::table('crm_campaign_recipients')
            ->where('campaign_id', $campaignId)
            ->where('company_id', $companyId)
            ->where('user_id', $userId)
            ->where('status', 'queued')
            ->exists();
        if ($hasQueuedRecipient) return;

        DB::table('crm_campaigns')
            ->where('id', $campaignId)
            ->where('company_id', $companyId)
            ->where('user_id', $userId)
            ->where('status', 'sending')
            ->update(['status' => 'sent', 'sent_at' => now(), 'updated_at' => now()]);
    }
}
