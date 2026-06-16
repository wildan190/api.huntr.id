<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Catalogue\Models\Catalogue;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class UpdateAdminCatalogueAction
{
    public function execute(Catalogue $catalogue, array $data): Catalogue
    {
        if (isset($data['image']) && $data['image'] instanceof UploadedFile) {
            $disk = config('filesystems.default') === 's3' ? 's3' : 'public';

            if ($catalogue->image_path) {
                Storage::disk($disk)->delete($catalogue->image_path);
            }

            $data['image_path'] = $data['image']->storePublicly('catalogues', $disk);
        }

        unset($data['image'], $data['price']);

        foreach (['company_id', 'item_code'] as $nonNullableField) {
            if (array_key_exists($nonNullableField, $data) && blank($data[$nonNullableField])) {
                unset($data[$nonNullableField]);
            }
        }

        $catalogue->update($data);

        return $catalogue->fresh('company');
    }
}
