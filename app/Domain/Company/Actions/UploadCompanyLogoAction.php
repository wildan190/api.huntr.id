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
        // Delete old logo if exists
        if ($company->logo_path) {
            Storage::disk(config('filesystems.default'))->delete($company->logo_path);
        }

        $path = $file->storePublicly('company_logos', config('filesystems.default'));
        $company->update(['logo_path' => $path]);
        
        return $company->fresh(['documents']);
    }
}
