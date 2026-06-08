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

        // Try various path mappings for files stored in local storage
        $fullPath = storage_path('app/' . $this->filePath);
        if (!file_exists($fullPath)) {
            $fullPath = storage_path($this->filePath);
        }
        if (!file_exists($fullPath)) {
            $fullPath = storage_path('app/private/' . $this->filePath);
        }

        if (!file_exists($fullPath)) {
            Log::error("ImportHistoricalPoJob: File not found at path: {$this->filePath}");
            return;
        }

        Log::info("ImportHistoricalPoJob: Starting import for company '{$company->name}' (ID: {$company->id}) from file {$fullPath}");

        try {
            $importedCount = $action->execute($company, $fullPath);
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
            if (file_exists($fullPath)) {
                @unlink($fullPath);
            }
        }
    }
}
