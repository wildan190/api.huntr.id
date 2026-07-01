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
                throw ValidationException::withMessages(['company_id' => 'Company ID is required.']);
            }
            
            $company = Company::findOrFail($companyId);
            
            $isVendor = false;
            if ($user instanceof User) {
                $isVendor = $user->companies()->where('companies.id', $company->id)->where('type', 'vendor')->exists();
            }

            if (!$isVendor) {
                throw ValidationException::withMessages(['message' => 'Only Vendors can add catalogue items to this company.']);
            }
        }

        $imagePath = null;
        if (isset($data['image']) && $data['image'] instanceof \Illuminate\Http\UploadedFile) {
            $disk = config('filesystems.default') === 's3' ? 's3' : 'public';
            $imagePath = $data['image']->storePublicly('catalogues', $disk);
        }

        $catalogue = Catalogue::create([
            'company_id' => $companyId,
            'item_code' => $data['item_code'],
            'name' => $data['name'],
            'category' => $data['category'] ?? null,
            'brand' => $data['brand'] ?? null,
            'specifications' => $data['specifications'] ?? null,
            'keywords' => $data['keywords'] ?? null,
            'uom' => $data['uom'],
            'price' => $data['price'],
            'image_path' => $imagePath,
        ]);
        
        // Trigger cache invalidation after creation
        $this->invalidateCatalogueCache();
        
        return $catalogue;
    }
    
    /**
     * Invalidate catalogue caches after creation/update.
     */
    private function invalidateCatalogueCache(): void
    {
        // This would be handled by the controller or event listener
        // For now, we'll rely on the controller to handle cache invalidation
    }
}
