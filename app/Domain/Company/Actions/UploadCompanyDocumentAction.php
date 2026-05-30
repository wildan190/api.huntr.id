<?php

namespace App\Domain\Company\Actions;

use App\Domain\Company\Models\Company;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class UploadCompanyDocumentAction
{
    /**
     * Upload a company document.
     *
     * @param array $data
     * @param UploadedFile $file
     * @return array
     */
    public function execute(array $data, UploadedFile $file): array
    {
        // 'local' disk is private (storage/app/private) and cannot generate public URLs.
        // Use 'public' disk for local environments; 's3' for staging/production.
        $disk = env('FILESYSTEM_DISK', 'public') === 'local' ? 'public' : env('FILESYSTEM_DISK', 'public');
        $path = $file->store('company_documents', $disk);
        $url = Storage::disk($disk)->url($path);
        $result = [
            'file_path' => $path,
            'url' => $url,
        ];

        if (!empty($data['company_id'])) {
            $company = Company::find($data['company_id']);
            if ($company) {
                $document = $company->documents()->create([
                    'name' => $file->getClientOriginalName(),
                    'type' => $data['type'] ?? 'Document',
                    'file_path' => $path,
                ]);
                $result['document'] = $document;
            }
        }

        return $result;
    }
}
