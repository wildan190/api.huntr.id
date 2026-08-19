<?php

namespace App\Domain\Order\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use App\Domain\Company\Models\Company;
use App\Domain\Order\Actions\ImportHistoricalPoAction;
use App\Domain\Communication\Actions\BroadcastWebsocketNotificationAction;

class ImportHistoricalPoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $companyId;
    protected $filePath;

    // Increase timeout to 15 minutes (900 seconds) for large imports
    // NOTE: Queue worker must also be run with --timeout >= 900
    // Example: php artisan queue:work --timeout=900
    public $timeout = 900;

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
    public function handle(ImportHistoricalPoAction $action): void
    {
        $company = Company::find($this->companyId);
        if (!$company) {
            Log::error("ImportHistoricalPoJob: Company ID {$this->companyId} not found.");
            return;
        }

        // Download file from S3 to a local temp file.
        // NOTE: We intentionally skip Storage::exists() because it sends an
        // unauthenticated HEAD request which returns 403 on this bucket.
        // Storage::get() uses signed SDK calls and works correctly.
        $disk     = \Illuminate\Support\Facades\Storage::disk(config('filesystems.default'));
        $tempFile = tempnam(sys_get_temp_dir(), 'po_import_');
        try {
            $contents = $disk->get($this->filePath);
        } catch (\Exception $e) {
            Log::error("ImportHistoricalPoJob: Cannot read file from storage at path: {$this->filePath}. Error: " . $e->getMessage());
            @unlink($tempFile);
            return;
        }
        file_put_contents($tempFile, $contents);
        unset($contents); // free memory

        Log::info("ImportHistoricalPoJob: Starting import for company '{$company->name}' (ID: {$company->id}) from file {$tempFile}");

        try {
            $importedCount = $action->execute($company, $tempFile);
            Log::info("ImportHistoricalPoJob: Successfully imported {$importedCount} historical PO items for company ID {$company->id}");

            // Broadcast success via WebSocket
            try {
                $broadcast = app(BroadcastWebsocketNotificationAction::class);
                $broadcast->execute(
                    "PO Import Completed",
                    "Successfully uploaded {$importedCount} historical PO items for {$company->name}.",
                    "company-{$company->id}",
                    false
                );
            } catch (\Exception $e) {
                Log::warning("ImportHistoricalPoJob WebSocket broadcast failed: " . $e->getMessage());
            }
        } catch (\Exception $e) {
            Log::error("ImportHistoricalPoJob Failed: " . $e->getMessage());

            // Broadcast error via WebSocket
            try {
                $broadcast = app(BroadcastWebsocketNotificationAction::class);
                $broadcast->execute(
                    "PO Import Failed",
                    "Failed to upload historical PO items: " . $e->getMessage(),
                    "company-{$company->id}",
                    false
                );
            } catch (\Exception $ex) {
                Log::warning("ImportHistoricalPoJob error broadcast failed: " . $ex->getMessage());
            }
        } finally {
            // Cleanup local temp file and S3 file
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
            try {
                $disk->delete($this->filePath);
            } catch (\Exception $e) {
                Log::warning("ImportHistoricalPoJob: Failed to delete S3 file: " . $e->getMessage());
            }
        }
    }
}
