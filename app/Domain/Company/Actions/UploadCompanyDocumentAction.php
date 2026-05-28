<?php

namespace App\Domain\Company\Actions;

use App\Domain\Company\Models\Company;
use Illuminate\Http\UploadedFile;

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
        $path = $file->store('company_documents', 'public');
        $url = asset('storage/' . $path);
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
