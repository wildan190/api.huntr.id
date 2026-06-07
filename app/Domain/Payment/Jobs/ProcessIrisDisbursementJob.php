<?php

namespace App\Domain\Payment\Jobs;

use App\Domain\Order\Models\Invoice;
use App\Domain\Payment\Services\MidtransIrisService;
use App\Domain\Communication\Actions\BroadcastWebsocketNotificationAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessIrisDisbursementJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = 30;

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected string $invoiceId,
        protected string $poNumber,
        protected array $payoutPayload,
        protected string|int $buyerUserId,
        protected array $vendorUserIds
    ) {}

    /**
     * Execute the job.
     */
    public function handle(
        MidtransIrisService $irisService,
        BroadcastWebsocketNotificationAction $broadcastAction
    ): void {
        $invoice = Invoice::with('purchaseOrder')->find($this->invoiceId);
        
        if (!$invoice) {
            Log::error('ProcessIrisDisbursementJob: Invoice not found', ['id' => $this->invoiceId]);
            return;
        }

        $po = $invoice->purchaseOrder;

        Log::info('Processing IRIS Disbursement in Background', [
            'invoice_id' => $invoice->id,
            'po_number' => $this->poNumber
        ]);

        try {
            // 1. Trigger IRIS Disbursement
            $irisService->createPayout(['payouts' => [$this->payoutPayload]]);

            // 2. Update status
            $invoice->update(['status' => 'paid']);
            $po->update(['status' => 'completed']);

            // 3. Notify Buyer
            $broadcastAction->execute(
                "Pembayaran Diteruskan ke Vendor",
                "Tim Finance telah menyetujui Invoice Akhir untuk PO {$this->poNumber}. Dana telah diteruskan ke vendor via Midtrans IRIS.",
                'test-channel',
                true,
                $this->buyerUserId,
                "/orders?search={$this->poNumber}"
            );

            // 4. Notify Vendor
            foreach ($this->vendorUserIds as $vendorUserId) {
                $broadcastAction->execute(
                    "Dana Telah Dicairkan",
                    "Buyer telah menyetujui Invoice Akhir untuk PO {$this->poNumber}. Dana sedang ditransfer ke rekening bank Anda.",
                    'test-channel',
                    true,
                    $vendorUserId,
                    "/orders?search={$this->poNumber}"
                );
            }

            Log::info('IRIS Disbursement Successful', [
                'invoice_id' => $invoice->id,
                'po_id' => $po->id
            ]);

        } catch (\Exception $e) {
            Log::error('IRIS Disbursement Failed in Job', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage()
            ]);
            
            // Revert status to let Finance retry or check what's wrong
            $invoice->update(['status' => 'pending_finance']);

            // Notify Finance/Buyer about failure
            $broadcastAction->execute(
                "Gagal Mencairkan Dana",
                "Pencairan dana otomatis ke Vendor gagal: {$e->getMessage()}. Silakan periksa kembali data rekening.",
                'test-channel',
                true,
                $this->buyerUserId,
                "/finance?search={$this->poNumber}"
            );

            // Re-throw so Horizon marks it as failed and can retry
            throw $e;
        }
    }
}
