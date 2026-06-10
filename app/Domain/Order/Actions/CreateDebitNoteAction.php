<?php

namespace App\Domain\Order\Actions;

use App\Domain\Order\Models\DebitNote;
use App\Domain\Order\Models\GoodsReturn;
use App\Domain\Order\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CreateDebitNoteAction
{
    /**
     * Create a debit note for returns, adjustments, or chargebacks
     *
     * @param array $data Debit note details
     * @return DebitNote
     */
    public function execute(array $data): DebitNote
    {
        // Validate required fields
        if (empty($data['po_id'])) {
            throw ValidationException::withMessages([
                'po_id' => ['Purchase order ID is required.'],
            ]);
        }

        if (empty($data['line_items'])) {
            throw ValidationException::withMessages([
                'line_items' => ['At least one line item is required.'],
            ]);
        }

        if (empty($data['type'])) {
            throw ValidationException::withMessages([
                'type' => ['Debit note type is required.'],
            ]);
        }

        return DB::transaction(function () use ($data) {
            $debitNote = new DebitNote([
                'po_id' => $data['po_id'],
                'invoice_id' => $data['invoice_id'] ?? null,
                'return_id' => $data['return_id'] ?? null,
                'buyer_company_id' => $data['buyer_company_id'],
                'vendor_company_id' => $data['vendor_company_id'],
                'debit_note_number' => DebitNote::generateDebitNoteNumber(),
                'debit_note_date' => $data['debit_note_date'] ?? now()->date(),
                'type' => $data['type'],
                'status' => $data['status'] ?? 'draft',
                'line_items' => $data['line_items'],
                'tax_rate' => $data['tax_rate'] ?? '10%',
                'currency' => $data['currency'] ?? 'IDR',
                'description' => $data['description'] ?? null,
                'reason_for_debit' => $data['reason_for_debit'] ?? null,
                'related_invoice_number' => $data['related_invoice_number'] ?? null,
                'attachments' => $data['attachments'] ?? null,
                'created_by' => $data['created_by'] ?? null,
            ]);

            // Calculate amounts
            $debitNote->calculateAmounts();
            $debitNote->save();

            Log::info('Debit note created successfully', [
                'debit_note_id' => $debitNote->id,
                'debit_note_number' => $debitNote->debit_note_number,
                'type' => $debitNote->type,
                'total_amount' => $debitNote->total_amount,
            ]);

            return $debitNote;
        });
    }

    /**
     * Create debit note from return
     */
    public function executeFromReturn(GoodsReturn $return, array $data = []): DebitNote
    {
        if (!$return->canBeApproved()) {
            throw ValidationException::withMessages([
                'return' => ['Return must be approved before creating a debit note.'],
            ]);
        }

        // Prepare line items from return items
        $lineItems = [];
        if ($return->items) {
            foreach ($return->items as $item) {
                $lineItems[] = [
                    'description' => $item['description'] ?? 'Return of goods',
                    'quantity' => $item['quantity_returned'] ?? 0,
                    'unit_price' => $item['unit_price'] ?? 0,
                    'amount' => ($item['quantity_returned'] ?? 0) * ($item['unit_price'] ?? 0),
                    'reason' => $return->return_reason,
                ];
            }
        }

        return $this->execute([
            'po_id' => $return->po_id,
            'return_id' => $return->id,
            'invoice_id' => $data['invoice_id'] ?? null,
            'buyer_company_id' => $return->buyer_company_id,
            'vendor_company_id' => $return->vendor_company_id,
            'type' => $data['type'] ?? 'return_refund',
            'line_items' => $lineItems,
            'reason_for_debit' => "Return of goods - Reason: {$return->return_reason}",
            'related_invoice_number' => $data['related_invoice_number'] ?? null,
            'tax_rate' => $data['tax_rate'] ?? '10%',
            'currency' => $data['currency'] ?? 'IDR',
            'created_by' => $data['created_by'] ?? null,
        ]);
    }
}
