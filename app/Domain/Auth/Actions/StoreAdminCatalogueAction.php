<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Auth\Models\Admin;
use App\Domain\Catalogue\Actions\CreateCatalogueAction;
use App\Domain\Catalogue\Models\Catalogue;
use Illuminate\Support\Str;

class StoreAdminCatalogueAction
{
    public function __construct(
        private readonly CreateCatalogueAction $createCatalogueAction
    ) {}

    public function execute(array $data): Catalogue
    {
        if (empty($data['item_code'])) {
            $data['item_code'] = 'GLB-' . strtoupper(Str::random(8));
        }

        $data['price'] = $data['price'] ?? 0;

        $admin = new Admin();
        $admin->id = 1;

        return $this->createCatalogueAction->execute($admin, $data);
    }
}
