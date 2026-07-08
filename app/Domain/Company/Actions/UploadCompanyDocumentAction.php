<?php

namespace App\Domain\Company\Actions;

use App\Domain\Company\Models\CompanyDocument;

class UploadCompanyDocumentAction
{
    /**
     * Upload a company document and save it to the database if company_id is provided.
     *
     * @param array $data
     * @return array
     */
    public function execute(array $data): array
    {
        $file = $data['document'];
        $companyId = $data['company_id'] ?? null;
        $type = $data['type'] ?? 'OTHER';

        $diskName = config('filesystems.default');
        \Illuminate\Support\Facades\Log::info('Uploading company document', ['disk' => $diskName, 'bucket' => config('filesystems.disks.'.$diskName.'.bucket')]);

        $path = $file->storePublicly('company_documents', $diskName);
        
        /** @var \Illuminate\Filesystem\FilesystemAdapter $storage */
        $storage = \Illuminate\Support\Facades\Storage::disk($diskName);
        $url = $storage->url($path);

        if ($companyId) {
            CompanyDocument::create([
                'company_id' => $companyId,
                'name' => $file->getClientOriginalName(),
                'type' => $type,
                'file_path' => $path,
            ]);
        }

        return [
            'file_path' => $path,
            'url' => $url,
            'localName' => $file->getClientOriginalName(),
        ];
    }
}
