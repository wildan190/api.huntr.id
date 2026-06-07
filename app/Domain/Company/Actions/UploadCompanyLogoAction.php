<?php

namespace App\Domain\Company\Actions;

use App\Domain\Company\Models\Company;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class UploadCompanyLogoAction
{
    /**
     * Upload and update company logo.
     *
     * @param Company $company
     * @param UploadedFile $file
     * @return Company
     */
    public function execute(Company $company, UploadedFile $file): Company
    {
        $disk = config('filesystems.default') === 's3' ? 's3' : 'public';
        // Delete old logo if exists
        if ($company->logo_path) {
            Storage::disk($disk)->delete($company->logo_path);
        }

        $path = $file->storePublicly('company_logos', $disk);
        $company->update(['logo_path' => $path]);
        
        return $company->fresh(['documents']);
    }
}
