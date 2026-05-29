<?php

namespace Tests\Feature;

use App\Domain\Company\Models\Company;
use App\Domain\Catalogue\Jobs\ImportCatalogueJob;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CatalogueImportTest extends TestCase
{
    /**
     * Test catalogue CSV import dispatches Horizon/Redis queue job successfully.
     */
    public function test_catalogue_import_endpoint_dispatches_queued_job(): void
    {
        Queue::fake();
        Storage::fake('local');

        // 1. Setup company
        $company = Company::create([
            'name' => 'Test Target Company',
            'type' => 'vendor',
            'status' => 'approved',
        ]);

        // 2. Mock a CSV file upload
        $csvHeader = "Inventory Code,Inventory name,Category,Specifications,Primary UOM,Unit price in original currency\n";
        $csvRow = "INV-001,Premium Keyboard,Hardware,RGB Mechanical,Pc,850000\n";
        $file = UploadedFile::fake()->createWithContent('inventory.csv', $csvHeader . $csvRow);

        // 3. Request import
        $response = $this->postJson('/api/catalogues/import', [
            'company_id' => $company->id,
            'csv' => $file,
        ]);

        // 4. Verify HTTP Response
        $response->assertStatus(202);
        $response->assertJson([
            'queued' => true
        ]);

        // 5. Verify file was saved and job was pushed to queue
        Queue::assertPushed(ImportCatalogueJob::class, function ($job) use ($company) {
            // Check that the job constructor received correct company ID
            $refCompanyId = (new \ReflectionClass($job))->getProperty('companyId');
            $refCompanyId->setAccessible(true);
            return $refCompanyId->getValue($job) === $company->id;
        });
    }
}
