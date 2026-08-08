<?php

namespace Tests\Domain\Catalogue\Jobs;

use Tests\TestCase;
use App\Domain\Catalogue\Jobs\ImportCatalogueJob;
use App\Domain\Company\Models\Company;
use App\Domain\Catalogue\Actions\ImportHistoricalDataAction;
use App\Domain\Communication\Actions\BroadcastWebsocketNotificationAction;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Exception;

class ImportCatalogueJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
    }

    public function test_job_exits_early_if_company_not_found()
    {
        Log::spy();

        $job = new ImportCatalogueJob('non-existent-uuid', 'imports/test.xlsx');
        $action = Mockery::mock(ImportHistoricalDataAction::class);
        $action->shouldNotReceive('execute');

        $job->handle($action);

        Log::shouldHaveReceived('error')
            ->once()
            ->with("ImportCatalogueJob: Company ID non-existent-uuid not found.");

        Storage::disk('s3')->assertMissing('imports/test.xlsx');
    }

    public function test_job_handles_s3_download_failure_and_throws_exception()
    {
        Log::spy();

        $company = Company::factory()->create();

        // Disk 's3' doesn't have the file, so get() throws exception
        $job = new ImportCatalogueJob($company->id, 'imports/missing.xlsx');
        $action = Mockery::mock(ImportHistoricalDataAction::class);

        $this->expectException(\Throwable::class);

        try {
            $job->handle($action);
        } finally {
            Log::shouldHaveReceived('info')->with("ImportCatalogueJob: Downloading file from S3: imports/missing.xlsx");
            Log::shouldHaveReceived('error')->with(Mockery::on(function ($message) {
                return str_contains($message, "ImportCatalogueJob: Failed to download S3 file imports/missing.xlsx");
            }));
            // S3 file cleanup should still be attempted in finally
            Log::shouldHaveReceived('info')->with("ImportCatalogueJob: S3 file cleaned up");
        }
    }

    public function test_job_successfully_downloads_imports_and_cleans_up()
    {
        Log::spy();

        $company = Company::factory()->create();
        $filePath = 'imports/test_catalogue.xlsx';
        $fileContent = "Inventory Code,Inventory Name\nITEM001,Product 1";

        Storage::disk('s3')->put($filePath, $fileContent);

        $action = Mockery::mock(ImportHistoricalDataAction::class);
        $action->shouldReceive('execute')
            ->once()
            ->with(Mockery::on(fn($c) => $c->id === $company->id), Mockery::type('string'))
            ->andReturn(5);

        $websocketAction = Mockery::mock(BroadcastWebsocketNotificationAction::class);
        $websocketAction->shouldReceive('execute')
            ->once()
            ->with("Import Completed", "Successfully uploaded 5 catalog items for {$company->name}.", "company-{$company->id}", false);

        $this->app->instance(BroadcastWebsocketNotificationAction::class, $websocketAction);

        $job = new ImportCatalogueJob($company->id, $filePath);
        $job->handle($action);

        Log::shouldHaveReceived('info')->with("ImportCatalogueJob: Downloading file from S3: {$filePath}");
        Log::shouldHaveReceived('info')->with("ImportCatalogueJob: File downloaded successfully");
        Log::shouldHaveReceived('info')->with("ImportCatalogueJob: Successfully imported 5 catalogue items for company ID {$company->id}");
        Log::shouldHaveReceived('info')->with("ImportCatalogueJob: Temporary file cleaned up");
        Log::shouldHaveReceived('info')->with("ImportCatalogueJob: S3 file cleaned up");

        // Assert S3 file was deleted
        Storage::disk('s3')->assertMissing($filePath);
    }

    public function test_job_handles_import_failure_broadcasts_error_and_cleans_up()
    {
        Log::spy();

        $company = Company::factory()->create();
        $filePath = 'imports/invalid_catalogue.xlsx';
        $fileContent = "Corrupted data";

        Storage::disk('s3')->put($filePath, $fileContent);

        $action = Mockery::mock(ImportHistoricalDataAction::class);
        $action->shouldReceive('execute')
            ->once()
            ->andThrow(new Exception("Parsing error"));

        $websocketAction = Mockery::mock(BroadcastWebsocketNotificationAction::class);
        $websocketAction->shouldReceive('execute')
            ->once()
            ->with("Import Failed", "Failed to upload catalog items: Parsing error", "company-{$company->id}", false);

        $this->app->instance(BroadcastWebsocketNotificationAction::class, $websocketAction);

        $job = new ImportCatalogueJob($company->id, $filePath);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Parsing error");

        try {
            $job->handle($action);
        } finally {
            Log::shouldHaveReceived('error')->with("ImportCatalogueJob Failed: Parsing error");
            Log::shouldHaveReceived('info')->with("ImportCatalogueJob: Temporary file cleaned up");
            Log::shouldHaveReceived('info')->with("ImportCatalogueJob: S3 file cleaned up");

            // Assert S3 file was deleted
            Storage::disk('s3')->assertMissing($filePath);
        }
    }
}
