<?php

namespace App\Domain\CrowdBuy\Console\Commands;

use App\Domain\CrowdBuy\Actions\EvaluateCampaignDeadlinesAction;
use Illuminate\Console\Command;

class EvaluateCrowdBuyDeadlinesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'crowd-buy:evaluate-deadlines';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Evaluate expired crowd buy campaigns, marking successful or failed outcomes and updating orders';

    /**
     * Execute the console command.
     */
    public function handle(EvaluateCampaignDeadlinesAction $action): int
    {
        $this->info('Starting Crowd Buy deadline evaluation...');

        $results = $action->execute();

        $this->info("Completed. Successful campaigns: {$results['successful']}, Failed campaigns: {$results['failed']}.");

        return self::SUCCESS;
    }
}
