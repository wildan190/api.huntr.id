<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use App\Domain\Rfq\Models\Rfq;
use App\Domain\Company\Models\CompanyDocument;
use App\Domain\Company\Models\Company;
use App\Domain\Company\Services\CompanyAccessService;
use Illuminate\Filesystem\FilesystemAdapter;

/**
 * DocumentController
 * 
 * Tanggung jawab: Mengelola akses ke dokumen yang disimpan di storage
 * dengan dukungan untuk local storage dan S3 dengan signed URLs.
 */
class DocumentController extends Controller
{
    public function downloadRfqDocument(Request $request, string $rfqId, CompanyAccessService $access)
    {
        try {
            $rfq = Rfq::with('proposals')->findOrFail($rfqId);
            $buyer = Company::query()->findOrFail($rfq->company_id);
            $hasVendorAccess = $rfq->proposals
                ->pluck('company_id')
                ->filter()
                ->contains(fn (string $companyId) => ($company = Company::query()->find($companyId)) && $access->isMember($request->user(), $company));
            abort_unless($access->isMember($request->user(), $buyer) || $hasVendorAccess, 403, 'Anda tidak memiliki akses ke dokumen ini.');
            
            if (!$rfq->document_path) {
                return response()->json(['message' => 'No document available'], 404);
            }

            Log::info('Serving RFQ document to user', [
                'rfq_id' => $rfqId,
                'user_id' => $request->user()?->id,
                'user_email' => $request->user()?->email,
                'document_path' => $rfq->document_path
            ]);

            return $this->serveDocument($rfq->document_path);

        } catch (\Illuminate\Auth\Access\AuthorizationException|\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface|\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Error downloading RFQ document:', [
                'error' => $e->getMessage(),
                'rfq_id' => $rfqId,
                'user_id' => $request->user()?->id,
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json(['message' => 'Error accessing document: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Download company document dengan validasi akses
     */
    public function downloadCompanyDocument(Request $request, string $documentId, CompanyAccessService $access)
    {
        try {
            $document = CompanyDocument::findOrFail($documentId);
            $company = Company::query()->findOrFail($document->company_id);
            $access->assertMember($request->user(), $company);

            return $this->serveDocument($document->file_path);

        } catch (\Illuminate\Auth\Access\AuthorizationException|\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface|\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Error downloading company document:', [
                'error' => $e->getMessage(),
                'document_id' => $documentId,
                'user_id' => $request->user()?->id,
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json(['message' => 'Error accessing document: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Serve document dari storage dengan dukungan S3 signed URLs
     * @param string $path
     */
    private function serveDocument(string $path)
    {
        try {
            $disk = config('filesystems.default');
            Log::info('serveDocument: starting', ['path' => $path, 'disk' => $disk]);

            /** @var FilesystemAdapter $storage */
            $storage = Storage::disk($disk);

            // For S3 or any other remote disk, redirect to public URL directly (avoiding HeadObject/exists/temporaryUrl 403 errors)
            if (in_array($disk, ['s3', 'spaces', 'gcs', 'azure'])) {
                try {
                    $url = $storage->url($path);
                    Log::info('Redirecting to storage URL', ['url' => $url, 'disk' => $disk]);
                    return redirect()->away($url);
                } catch (\Exception $e) {
                    Log::error('Error generating storage URL:', [
                        'error' => $e->getMessage(),
                        'path' => $path,
                        'trace' => $e->getTraceAsString()
                    ]);
                    return response()->json(['message' => 'Error generating download URL: ' . $e->getMessage()], 500);
                }
            }

            if (!$storage->exists($path)) {
                Log::warning('Document not found on local disk', ['path' => $path]);
                return response()->json(['message' => 'Document not found'], 404);
            }

            // For local disk (public or local), serve directly
            try {
                $filename = basename($path);
                $mimeType = $storage->mimeType($path) ?: 'application/octet-stream';
                
                Log::info('Serving local file', [
                    'path' => $path,
                    'mime_type' => $mimeType,
                    'filename' => $filename,
                    'storage_path' => $storage->path($path)
                ]);

                return response()->file(
                    $storage->path($path),
                    [
                        'Content-Type' => $mimeType,
                        'Content-Disposition' => 'inline; filename="' . $filename . '"'
                    ]
                );
            } catch (\Exception $e) {
                Log::error('Error serving local file:', [
                    'error' => $e->getMessage(),
                    'path' => $path,
                    'trace' => $e->getTraceAsString()
                ]);
                return response()->json(['message' => 'Error serving document: ' . $e->getMessage()], 500);
            }
        } catch (\Exception $e) {
            Log::error('serveDocument: top-level exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['message' => 'Server error: ' . $e->getMessage()], 500);
        }
    }

    public function downloadAsset(Request $request, string $path)
    {
        try {
            abort_unless($this->isPublicAssetPath($path), 404);
            Log::info('Serving generic asset to user', [
                'path' => $path,
                'user_id' => $request->user()?->id,
                'user_email' => $request->user()?->email
            ]);
            return $this->serveDocument($path);
        } catch (\Exception $e) {
            Log::error('Error downloading generic asset:', [
                'error' => $e->getMessage(),
                'path' => $path,
                'user_id' => $request->user()?->id,
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json(['message' => 'Error accessing asset: ' . $e->getMessage()], 500);
        }
    }

    public function getAssetUrl(Request $request)
    {
        $path = $request->query('path');
        if (!$path) {
            return response()->json(['message' => 'Path parameter required'], 400);
        }
        abort_unless($this->isPublicAssetPath($path), 404);

        $disk = config('filesystems.default');
        /** @var FilesystemAdapter $storage */
        $storage = Storage::disk($disk);

        if (!$storage->exists($path)) {
            return response()->json(['message' => 'Asset not found'], 404);
        }

        // Generate public URL
        try {
            $url = $storage->url($path);
            return response()->json(['url' => $url]);
        } catch (\Exception $e) {
            Log::error('Error generating asset URL:', [
                'error' => $e->getMessage(),
                'path' => $path
            ]);
            
            return response()->json(['message' => 'Error generating asset URL'], 500);
        }
    }

    private function isPublicAssetPath(string $path): bool
    {
        if (str_contains($path, "\0") || str_contains($path, '..')) {
            return false;
        }

        return str_starts_with($path, 'company_logos/')
            || str_starts_with($path, 'catalogue_images/')
            || str_starts_with($path, 'product_images/');
    }
}
