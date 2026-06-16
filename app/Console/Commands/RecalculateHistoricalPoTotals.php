<?php

namespace App\Console\Commands;

use App\Domain\Order\Models\PurchaseOrder;
use Illuminate\Console\Command;

class RecalculateHistoricalPoTotals extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:recalculate-historical-po-totals';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Recalculate total_amount for historical POs based on their line items';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting to recalculate historical PO totals...');

        $count = 0;
        $pos = PurchaseOrder::where('is_historical', true)->with('historicalItems')->get();

        foreach ($pos as $po) {
            $total = $po->historicalItems->sum('total_amount');
            if ($po->total_amount != $total) {
                $po->update(['total_amount' => $total]);
                $count++;
                $this->line("Updated PO {$po->po_number}: set total to {$total}");
            }
        }

        $this->info("Done! Updated {$count} POs.");
    }
}
