<?php

namespace App\Domain\Order\Notifications;

use App\Domain\Order\Models\Bast;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class BastIssuedNotification extends Notification
{
    use Queueable;

    public Bast $bast;
    public string $bastNumber;
    public string $vendorName;
    public string $poNumber;

    public function __construct(Bast $bast)
    {
        $this->bast = $bast;
        $this->bastNumber = $bast->bast_number;
        $this->vendorName = $bast->vendorCompany?->name ?? 'Vendor';
        $this->poNumber = $bast->purchaseOrder?->po_number ?? 'N/A';
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'bast_issued',
            'title' => 'BAST Issued',
            'message' => "BAST {$this->bastNumber} has been issued by {$this->vendorName} for PO {$this->poNumber}",
            'bast_id' => $this->bast->id,
            'bast_number' => $this->bastNumber,
            'po_id' => $this->bast->po_id,
            'po_number' => $this->poNumber,
            'vendor_id' => $this->bast->vendor_company_id,
            'vendor_name' => $this->vendorName,
            'icon' => 'Signature',
            'color' => 'purple',
        ];
    }
}
