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
    public function __construct(int $companyId, string $filePath)
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

        // Try various path mappings for files stored in local storage
        $fullPath = storage_path('app/' . $this->filePath);
        if (!file_exists($fullPath)) {
            $fullPath = storage_path($this->filePath);
        }
        if (!file_exists($fullPath)) {
            $fullPath = storage_path('app/private/' . $this->filePath);
        }

        if (!file_exists($fullPath)) {
            Log::error("ImportCatalogueJob: CSV file not found at path: {$this->filePath}");
            return;
        }

        Log::info("ImportCatalogueJob: Starting import for company '{$company->name}' (ID: {$company->id}) from file {$fullPath}");

        try {
            $importedCount = $action->execute($company, $fullPath);
            Log::info("ImportCatalogueJob: Successfully imported {$importedCount} catalogue items for company ID {$company->id}");

            // Broadcast success via WebSocket
            try {
                $broadcast = app(BroadcastWebsocketNotificationAction::class);
                $broadcast->execute(
                    "Import Selesai",
                    "Berhasil mengunggah {$importedCount} data katalog untuk perusahaan {$company->name}.",
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
                    "Import Gagal",
                    "Gagal mengunggah data katalog: " . $e->getMessage(),
                    "company-{$company->id}",
                    false
                );
            } catch (\Exception $ex) {
                Log::warning("ImportCatalogueJob error broadcast failed: " . $ex->getMessage());
            }
        } finally {
            if (file_exists($fullPath)) {
                @unlink($fullPath);
            }
        }
    }
}
