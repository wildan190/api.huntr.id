<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use App\Domain\Rfq\Models\Rfq;
use App\Domain\Company\Models\CompanyDocument;

/**
 * DocumentController
 * 
 * Tanggung jawab: Mengelola akses ke dokumen yang disimpan di storage
 * dengan dukungan untuk local storage dan S3 dengan signed URLs.
 */
class DocumentController extends Controller
{
    /**
     * Download RFQ document dengan validasi akses
     */
    public function downloadRfqDocument(Request $request, $rfqId)
    {
        try {
            Log::info('Download RFQ document called', [
                'path' => $request->path(),
                'has_token_query' => $request->has('token'),
                'has_auth_header' => $request->hasHeader('Authorization'),
            ]);
            
            // Coba autentikasi via header terlebih dahulu, lalu via query param
            $user = $request->user();
            Log::info('User from request', ['user_id' => $user?->id]);
            
            if (!$user && $request->has('token')) {
                Log::info('Trying token from query parameter');
                $token = \Laravel\Sanctum\PersonalAccessToken::findToken($request->token);
                if ($token) {
                    Log::info('Found token', ['token_id' => $token->id, 'tokenable_id' => $token->tokenable_id]);
                    $user = $token->tokenable;
                    Auth::login($user);
                } else {
                    Log::warning('No token found for query parameter');
                }
            }
            
            if (!$user) {
                Log::warning('User not authenticated for document download');
                return response()->json(['message' => 'Authentication required'], 401);
            }

            $rfq = Rfq::findOrFail($rfqId);
            
            if (!$rfq->document_path) {
                return response()->json(['message' => 'No document available'], 404);
            }

            return $this->serveDocument($rfq->document_path);

        } catch (\Exception $e) {
            Log::error('Error downloading RFQ document:', [
                'error' => $e->getMessage(),
                'rfq_id' => $rfqId,
                'user_id' => $request->user()?->id
            ]);
            
            return response()->json(['message' => 'Error accessing document'], 500);
        }
    }

    /**
     * Download company document dengan validasi akses
     */
    public function downloadCompanyDocument(Request $request, $documentId)
    {
        try {
            Log::info('Download Company document called', [
                'path' => $request->path(),
                'has_token_query' => $request->has('token'),
                'has_auth_header' => $request->hasHeader('Authorization'),
            ]);
            
            // Coba autentikasi via header terlebih dahulu, lalu via query param
            $user = $request->user();
            Log::info('User from request', ['user_id' => $user?->id]);
            
            if (!$user && $request->has('token')) {
                Log::info('Trying token from query parameter');
                $token = \Laravel\Sanctum\PersonalAccessToken::findToken($request->token);
                if ($token) {
                    Log::info('Found token', ['token_id' => $token->id, 'tokenable_id' => $token->tokenable_id]);
                    $user = $token->tokenable;
                    Auth::login($user);
                } else {
                    Log::warning('No token found for query parameter');
                }
            }
            
            if (!$user) {
                Log::warning('User not authenticated for company document download');
                return response()->json(['message' => 'Authentication required'], 401);
            }

            $document = CompanyDocument::findOrFail($documentId);

            return $this->serveDocument($document->file_path);

        } catch (\Exception $e) {
            Log::error('Error downloading company document:', [
                'error' => $e->getMessage(),
                'document_id' => $documentId,
                'user_id' => $request->user()?->id
            ]);
            
            return response()->json(['message' => 'Error accessing document'], 500);
        }
    }

    /**
     * Serve document dari storage dengan dukungan S3 signed URLs
     */
    private function serveDocument($path)
    {
        $disk = config('filesystems.default');
        $storage = Storage::disk($disk);

        Log::info('Serving document', [
            'path' => $path,
            'disk' => $disk
        ]);

        if (!$storage->exists($path)) {
            Log::warning('Document not found', ['path' => $path]);
            return response()->json(['message' => 'Document not found'], 404);
        }

        // Untuk S3, redirect ke signed URL atau stream langsung
        if ($disk === 's3') {
            try {
                // Generate signed URL yang berlaku selama 1 jam
                $signedUrl = $storage->temporaryUrl($path, now()->addHours(1));
                Log::info('Generated S3 signed URL', ['url' => $signedUrl]);
                
                return redirect()->away($signedUrl);
                
            } catch (\Exception $e) {
                Log::error('Error generating S3 signed URL:', [
                    'error' => $e->getMessage(),
                    'path' => $path,
                    'trace' => $e->getTraceAsString()
                ]);
                
                return response()->json(['message' => 'Error generating download URL: ' . $e->getMessage()], 500);
            }
        }

        // Untuk local storage, stream file langsung
        try {
            $fileContent = $storage->get($path);
            $mimeType = $storage->mimeType($path);
            $filename = basename($path);

            Log::info('Serving local file', [
                'path' => $path,
                'mime_type' => $mimeType,
                'filename' => $filename
            ]);

            return response($fileContent)
                ->header('Content-Type', $mimeType)
                ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
                
        } catch (\Exception $e) {
            Log::error('Error serving local file:', [
                'error' => $e->getMessage(),
                'path' => $path,
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json(['message' => 'Error serving document: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Get public asset URL (for images, etc.)
     */
    public function getAssetUrl(Request $request)
    {
        $path = $request->query('path');
        if (!$path) {
            return response()->json(['message' => 'Path parameter required'], 400);
        }

        $disk = config('filesystems.default');
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
}