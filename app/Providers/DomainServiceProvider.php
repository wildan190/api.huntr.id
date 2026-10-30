<?php

namespace App\Providers;

use App\Domain\Auth\Repositories\EloquentUserRepository;
// Auth
use App\Domain\Auth\Repositories\UserRepositoryInterface;
use App\Domain\Catalogue\Repositories\CatalogueRepositoryInterface;
// Company
use App\Domain\Catalogue\Repositories\EloquentCatalogueRepository;
use App\Domain\Catalogue\Services\CatalogueCacheService;
// Catalogue
use App\Domain\Company\Repositories\CompanyRepositoryInterface;
use App\Domain\Company\Repositories\EloquentCompanyRepository;
use App\Domain\Crm\Console\DispatchCrmCampaignsCommand;
// Rfq
use App\Domain\Crm\Console\EscalateCrmTicketSlaCommand;
use App\Domain\CrowdBuy\Console\Commands\AggregateHistoricalDemandsCommand;
// Proposal
use App\Domain\CrowdBuy\Console\Commands\EvaluateCrowdBuyDeadlinesCommand;
use App\Domain\CrowdBuy\Repositories\CrowdBuyRepositoryInterface;
// Order
use App\Domain\CrowdBuy\Repositories\EloquentCrowdBuyRepository;
use App\Domain\Order\Repositories\EloquentOrderRepository;
// Receipt
use App\Domain\Order\Repositories\OrderRepositoryInterface;
use App\Domain\Proposal\Repositories\EloquentProposalRepository;
use App\Domain\Proposal\Repositories\ProposalRepositoryInterface;
use App\Domain\Receipt\Repositories\EloquentReceiptRepository;
use App\Domain\Receipt\Repositories\ReceiptRepositoryInterface;
use App\Domain\Rfq\Repositories\EloquentRfqRepository;
use App\Domain\Rfq\Repositories\RfqRepositoryInterface;
use Illuminate\Support\ServiceProvider;

class DomainServiceProvider extends ServiceProvider
{
    /**
     * Bind all domain repository interfaces to their Eloquent implementations.
     * Actions are resolved via the container and receive their repositories automatically.
     */
    public function register(): void
    {
        $this->app->bind(UserRepositoryInterface::class, EloquentUserRepository::class);
        $this->app->bind(CompanyRepositoryInterface::class, EloquentCompanyRepository::class);
        $this->app->bind(CatalogueRepositoryInterface::class, EloquentCatalogueRepository::class);
        $this->app->bind(RfqRepositoryInterface::class, EloquentRfqRepository::class);
        $this->app->bind(ProposalRepositoryInterface::class, EloquentProposalRepository::class);
        $this->app->bind(OrderRepositoryInterface::class, EloquentOrderRepository::class);
        $this->app->bind(ReceiptRepositoryInterface::class, EloquentReceiptRepository::class);
        $this->app->bind(CrowdBuyRepositoryInterface::class, EloquentCrowdBuyRepository::class);

        // Register catalogue cache service as singleton for better performance
        $this->app->singleton(CatalogueCacheService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $domains = ['Admin', 'Auth', 'Company', 'Catalogue', 'Rfq', 'Proposal', 'Order', 'Receipt', 'Communication', 'Access', 'Payment', 'Negotiation', 'EFaktur', 'AI', 'Subscription', 'Wms', 'Crm', 'Budget', 'CrowdBuy', 'News'];
        foreach ($domains as $domain) {
            $routePath = app_path("Domain/{$domain}/routes/api.php");
            if (file_exists($routePath)) {
                $this->loadRoutesFrom($routePath);
            }
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                EvaluateCrowdBuyDeadlinesCommand::class,
                AggregateHistoricalDemandsCommand::class,
                DispatchCrmCampaignsCommand::class,
                EscalateCrmTicketSlaCommand::class,
            ]);
        }
    }
}
