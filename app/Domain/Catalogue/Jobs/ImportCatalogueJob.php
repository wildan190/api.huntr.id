<?php

namespace App\Domain\Catalogue\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use App\Domain\Company\Models\Company;
use App\Domain\Catalogue\Actions\ImportHistoricalDataAction;
use App\Domain\Communication\Actions\BroadcastWebsocketNotificationAction;

class ImportCatalogueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $companyId;
    protected $filePath;

    /**
     * Create a new job instance.
     */
    public function __construct(string $companyId, string $filePath)
    {
        $this->companyId = $companyId;
        $this->filePath = $filePath;
    }

    /**
     * Execute the job.
     */
    public function handle(ImportHistoricalDataAction $action): void
    {
        $company = Company::find($this->companyId);
        if (!$company) {
            Log::error("ImportCatalogueJob: Company ID {$this->companyId} not found.");
            return;
        }

        $disk = \Illuminate\Support\Facades\Storage::disk('s3');
        $tempFile = null;

        try {
            Log::info("ImportCatalogueJob: Downloading file from S3: {$this->filePath}");
            $fileContent = $disk->get($this->filePath);
            Log::info("ImportCatalogueJob: File downloaded successfully");

            $tempFile = tempnam(sys_get_temp_dir(), 'catalogue_import_');
            file_put_contents($tempFile, $fileContent);
        } catch (\Throwable $e) {
            Log::error("ImportCatalogueJob: Failed to download S3 file {$this->filePath}: " . $e->getMessage());
            throw $e;
        }

        try {
            Log::info("ImportCatalogueJob: Starting import for company '{$company->name}' (ID: {$company->id}) from file {$tempFile}");
            $importedCount = $action->execute($company, $tempFile);
            Log::info("ImportCatalogueJob: Successfully imported {$importedCount} catalogue items for company ID {$company->id}");

            // Broadcast success via WebSocket
            try {
                $broadcast = app(BroadcastWebsocketNotificationAction::class);
                $broadcast->execute(
                    "Import Completed",
                    "Successfully uploaded {$importedCount} catalog items for {$company->name}.",
                    "company-{$company->id}",
                    false
                );
            } catch (\Throwable $e) {
                Log::warning("ImportCatalogueJob WebSocket broadcast failed: " . $e->getMessage());
            }
        } catch (\Throwable $e) {
            Log::error("ImportCatalogueJob Failed: " . $e->getMessage());

            // Broadcast error via WebSocket
            try {
                $broadcast = app(BroadcastWebsocketNotificationAction::class);
                $broadcast->execute(
                    "Import Failed",
                    "Failed to upload catalog items: " . $e->getMessage(),
                    "company-{$company->id}",
                    false
                );
            } catch (\Throwable $ex) {
                Log::warning("ImportCatalogueJob error broadcast failed: " . $ex->getMessage());
            }

            throw $e;
        } finally {
            // Cleanup local temp file
            if ($tempFile && file_exists($tempFile)) {
                @unlink($tempFile);
                Log::info("ImportCatalogueJob: Temporary file cleaned up");
            }
            // Cleanup S3 object
            try {
                $disk->delete($this->filePath);
                Log::info("ImportCatalogueJob: S3 file cleaned up");
            } catch (\Throwable $e) {
                Log::warning("ImportCatalogueJob: Failed to delete S3 file: " . $e->getMessage());
            }
        }
    }
}
