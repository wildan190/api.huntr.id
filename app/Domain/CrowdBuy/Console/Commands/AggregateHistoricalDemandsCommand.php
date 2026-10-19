<?php

namespace App\Domain\CrowdBuy\Console\Commands;

use App\Domain\CrowdBuy\Services\AutoDemandAggregationService;
use Illuminate\Console\Command;

class AggregateHistoricalDemandsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'crowd-buy:aggregate-demands {--min-freq=1 : Minimum order frequency} {--limit=20 : Maximum items to process}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Analyze buyer historical PO data across all companies in HUNTR and auto-generate crowd buy campaigns';

    /**
     * Execute the console command.
     */
    public function handle(AutoDemandAggregationService $service): int
    {
        $this->info('Starting HUNTR historical PO demand aggregation...');

        $minFreq = (int) $this->option('min-freq');
        $limit = (int) $this->option('limit');

        $result = $service->generateCampaigns([
            'min_frequency' => $minFreq,
            'limit' => $limit,
        ]);

        $this->info("Aggregation completed.");
        $this->line("- Items analyzed: {$result['total_analyzed']}");
        $this->line("- New campaigns created: {$result['created_count']}");
        $this->line("- Existing campaigns updated: {$result['updated_count']}");

        return self::SUCCESS;
    }
}
