<?php

namespace Tests\Feature;

use App\Domain\Auth\Models\User;
use App\Domain\Catalogue\Models\Catalogue;
use App\Domain\Company\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogueDataLeakTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function public_catalogue_index_does_not_expose_sensitive_company_fields()
    {
        $company = Company::factory()->create([
            'type' => 'vendor',
            'status' => 'approved',
            'tax_id' => '12345678901234',
            'bank_name' => 'BCA',
            'bank_account' => '1234567890',
            'bank_account_name' => 'PT Secret Owner',
            'verification_notes' => 'Internal note: verified by Kemenkumham',
            'phone' => '+6281200000000',
            'address' => 'Jl. Rahasia No. 1',
            'city' => 'Jakarta',
            'zip_code' => '12345',
            'country' => 'ID',
        ]);

        Catalogue::create([
            'company_id' => $company->id,
            'item_code' => 'ITEM-001',
            'name' => 'Public Product',
            'category' => 'Electronics',
            'brand' => 'BrandX',
            'specifications' => 'Specs here',
        ]);

        $response = $this->getJson('/api/catalogues');
        $response->assertStatus(200);

        $companyData = $response->json('data.0.company');

        // Safe public fields should be present
        $this->assertEquals($company->id, $companyData['id']);
        $this->assertEquals($company->name, $companyData['name']);
        $this->assertEquals($company->type, $companyData['type']);
        $this->assertEquals($company->email, $companyData['email']);
        $this->assertEquals($company->industry_type, $companyData['industry_type']);

        // Sensitive fields MUST NOT be present in public response
        $this->assertArrayNotHasKey('tax_id', $companyData);
        $this->assertArrayNotHasKey('bank_name', $companyData);
        $this->assertArrayNotHasKey('bank_account', $companyData);
        $this->assertArrayNotHasKey('bank_account_name', $companyData);
        $this->assertArrayNotHasKey('verification_notes', $companyData);
        $this->assertArrayNotHasKey('phone', $companyData);
        $this->assertArrayNotHasKey('address', $companyData);
        $this->assertArrayNotHasKey('city', $companyData);
        $this->assertArrayNotHasKey('zip_code', $companyData);
        $this->assertArrayNotHasKey('keywords', $companyData);
        $this->assertArrayNotHasKey('hq_addresses', $companyData);
        $this->assertArrayNotHasKey('region', $companyData);
        $this->assertArrayNotHasKey('provincy_country', $companyData);
        $this->assertArrayNotHasKey('regency', $companyData);
        $this->assertArrayNotHasKey('formatted_tax_id', $companyData);
    }

    /** @test */
    public function public_catalogue_show_does_not_expose_sensitive_company_fields()
    {
        $company = Company::factory()->create([
            'type' => 'vendor',
            'status' => 'approved',
            'tax_id' => '99887766554433',
            'bank_name' => 'Mandiri',
            'bank_account' => '000011112222',
            'bank_account_name' => 'PT Very Confidential',
            'verification_notes' => 'Private verification notes',
        ]);

        $catalogue = Catalogue::create([
            'company_id' => $company->id,
            'item_code' => 'ITEM-002',
            'name' => 'Detail Product',
            'category' => 'Office',
            'brand' => 'BrandY',
            'specifications' => 'Detailed specs',
        ]);

        $response = $this->getJson("/api/catalogues/{$catalogue->id}");
        $response->assertStatus(200);

        $companyData = $response->json('data.company');

        // Public fields are OK
        $this->assertEquals($company->name, $companyData['name']);
        $this->assertEquals($company->type, $companyData['type']);

        // Sensitive fields are excluded
        $this->assertArrayNotHasKey('tax_id', $companyData);
        $this->assertArrayNotHasKey('bank_name', $companyData);
        $this->assertArrayNotHasKey('bank_account', $companyData);
        $this->assertArrayNotHasKey('bank_account_name', $companyData);
        $this->assertArrayNotHasKey('verification_notes', $companyData);
        $this->assertArrayNotHasKey('formatted_tax_id', $companyData);
    }
}
