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
        $diskName = config('filesystems.default');
        \Illuminate\Support\Facades\Log::info('Uploading company logo', ['disk' => $diskName, 'bucket' => config('filesystems.disks.'.$diskName.'.bucket')]);

        // Delete old logo if exists
        if ($company->logo_path) {
            Storage::disk($diskName)->delete($company->logo_path);
        }

        $path = $file->storePublicly('company_logos', $diskName);
        $company->update(['logo_path' => $path]);
        
        return $company->fresh(['documents']);
    }
}
