<?php

namespace App\Domain\Catalogue\Actions;

use App\Domain\Catalogue\Models\Catalogue;
use Illuminate\Support\Facades\Storage;

class DeleteAdminCatalogueAction
{
    public function execute(Catalogue $catalogue): void
    {
        if ($catalogue->image_path) {
            Storage::disk(config('filesystems.default'))->delete($catalogue->image_path);
        }

        $catalogue->delete();
    }
}
