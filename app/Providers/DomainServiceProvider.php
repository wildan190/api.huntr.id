<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

// Auth
use App\Domain\Auth\Repositories\UserRepositoryInterface;
use App\Domain\Auth\Repositories\EloquentUserRepository;

// Company
use App\Domain\Company\Repositories\CompanyRepositoryInterface;
use App\Domain\Company\Repositories\EloquentCompanyRepository;

// Catalogue
use App\Domain\Catalogue\Repositories\CatalogueRepositoryInterface;
use App\Domain\Catalogue\Repositories\EloquentCatalogueRepository;

// Rfq
use App\Domain\Rfq\Repositories\RfqRepositoryInterface;
use App\Domain\Rfq\Repositories\EloquentRfqRepository;

// Proposal
use App\Domain\Proposal\Repositories\ProposalRepositoryInterface;
use App\Domain\Proposal\Repositories\EloquentProposalRepository;

// Order
use App\Domain\Order\Repositories\OrderRepositoryInterface;
use App\Domain\Order\Repositories\EloquentOrderRepository;

// Receipt
use App\Domain\Receipt\Repositories\ReceiptRepositoryInterface;
use App\Domain\Receipt\Repositories\EloquentReceiptRepository;

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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $domains = ['Auth', 'Company', 'Catalogue', 'Rfq', 'Proposal', 'Order', 'Receipt', 'Communication'];
        foreach ($domains as $domain) {
            $routePath = app_path("Domain/{$domain}/routes/api.php");
            if (file_exists($routePath)) {
                $this->loadRoutesFrom($routePath);
            }
        }
    }
}

