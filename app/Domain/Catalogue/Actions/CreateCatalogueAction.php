<?php

namespace App\Domain\Catalogue\Actions;

use App\Domain\Catalogue\Models\Catalogue;
use App\Domain\Company\Models\Company;
use App\Domain\Auth\Models\Admin;
use App\Domain\Auth\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Action to create a new catalogue item.
 */
class CreateCatalogueAction
{
    /**
     * Execute the action.
     *
     * @param mixed $user The authenticated user
     * @param array $data The validated data
     * @return Catalogue
     * @throws ValidationException
     */
    public function execute($user, array $data): Catalogue
    {
        $isAdmin = $user instanceof Admin;
        $companyId = $data['company_id'] ?? null;

        if ($isAdmin) {
            if (!$companyId) {
                $adminCompany = Company::firstOrCreate(
                    ['name' => 'Admin Marketplace', 'type' => 'vendor'],
                    ['status' => 'approved']
                );
                $companyId = $adminCompany->id;
            }
        } else {
            if (!$companyId) {
                throw ValidationException::withMessages(['company_id' => 'ID Perusahaan wajib diisi.']);
            }
            
            $company = Company::findOrFail($companyId);
            
            $isVendor = false;
            if ($user instanceof User) {
                $isVendor = $user->companies()->where('companies.id', $company->id)->where('type', 'vendor')->exists();
            }

            if (!$isVendor) {
                throw ValidationException::withMessages(['message' => 'Hanya Vendor yang dapat menambahkan katalog ke perusahaan ini.']);
            }
        }

        $imagePath = null;
        if (isset($data['image']) && $data['image'] instanceof \Illuminate\Http\UploadedFile) {
            $disk = config('filesystems.default') === 's3' ? 's3' : 'public';
            $imagePath = $data['image']->store('catalogues', $disk);
        }

        return Catalogue::create([
            'company_id' => $companyId,
            'item_code' => $data['item_code'],
            'name' => $data['name'],
            'category' => $data['category'] ?? null,
            'specifications' => $data['specifications'] ?? null,
            'uom' => $data['uom'],
            'price' => $data['price'],
            'image_path' => $imagePath,
        ]);
    }
}
