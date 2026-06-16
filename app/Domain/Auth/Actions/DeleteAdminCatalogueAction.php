<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Catalogue\Models\Catalogue;
use Illuminate\Support\Facades\Storage;

class DeleteAdminCatalogueAction
{
    public function execute(Catalogue $catalogue): void
    {
        $disk = config('filesystems.default') === 's3' ? 's3' : 'public';

        if ($catalogue->image_path) {
            Storage::disk($disk)->delete($catalogue->image_path);
        }

        $catalogue->delete();
    }
}
