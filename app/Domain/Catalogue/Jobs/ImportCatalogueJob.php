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

        $disk = \Illuminate\Support\Facades\Storage::disk(config('filesystems.default'));
        if (!$disk->exists($this->filePath)) {
            Log::error("ImportCatalogueJob: CSV file not found at path: {$this->filePath}");
            return;
        }

        // Download file from S3 to local temp file
        $tempFile = tempnam(sys_get_temp_dir(), 'catalogue_import_');
        file_put_contents($tempFile, $disk->get($this->filePath));

        Log::info("ImportCatalogueJob: Starting import for company '{$company->name}' (ID: {$company->id}) from file {$tempFile}");

        try {
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
            } catch (\Exception $e) {
                Log::warning("ImportCatalogueJob WebSocket broadcast failed: " . $e->getMessage());
            }
        } catch (\Exception $e) {
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
            } catch (\Exception $ex) {
                Log::warning("ImportCatalogueJob error broadcast failed: " . $ex->getMessage());
            }
        } finally {
            // Cleanup local temp file and S3 file
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
            try {
                $disk->delete($this->filePath);
            } catch (\Exception $e) {
                Log::warning("ImportCatalogueJob: Failed to delete S3 file: " . $e->getMessage());
            }
        }
    }
}
