<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class TestS3Upload extends Command
{
    protected $signature = 'test:s3-upload';
    protected $description = 'Test uploading a file to S3';

    public function handle()
    {
        $this->info('Starting S3 upload test...');
        Log::info('TestS3Upload starting');

        $diskName = config('filesystems.default');
        $this->info("Using disk: {$diskName}");
        Log::info('Using disk', ['disk' => $diskName, 'config' => config('filesystems.disks.'.$diskName)]);

        try {
            // Create a temporary file
            $tempFile = tempnam(sys_get_temp_dir(), 'test');
            file_put_contents($tempFile, "Test file content at ".now()->toDateTimeString());
            
            $uploadedFile = new UploadedFile(
                $tempFile,
                'test-'.uniqid().'.txt',
                'text/plain',
                null,
                true
            );
            
            $path = $uploadedFile->storePublicly('tests', $diskName);
            
            $this->info("File uploaded successfully! Path: $path");
            Log::info('Test file uploaded', ['path' => $path]);

            $url = Storage::disk($diskName)->url($path);
            $this->info("URL: $url");
            Log::info('Test file URL', ['url' => $url]);

            unlink($tempFile); // Cleanup temp file

            $this->info('S3 test completed successfully!');
            return 0;
        } catch (\Exception $e) {
            $this->error('S3 upload failed: ' . $e->getMessage());
            $this->error('Stack trace: ' . $e->getTraceAsString());
            Log::error('S3 test failed', ['exception' => $e]);
            return 1;
        }
    }
}
