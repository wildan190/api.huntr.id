<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Domain\Company\Models\Company;
use App\Domain\Catalogue\Models\Catalogue;
use App\Domain\Auth\Models\User;

class SeoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.frontend_url' => 'https://app.huntr.id']);
    }

    public function test_robots_txt_endpoint()
    {
        $response = $this->get('/robots.txt');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $response->assertSee('User-agent: *');
        $response->assertSee('Sitemap: https://app.huntr.id/sitemap.xml');
    }

    public function test_llms_txt_endpoint()
    {
        // Setup a product
        $company = Company::create([
            'name' => 'Test Vendor Corp',
            'type' => 'vendor',
            'status' => 'approved',
            'owner_id' => null
        ]);

        Catalogue::create([
            'company_id' => $company->id,
            'item_code' => 'CODE-123',
            'name' => 'Awesome Product A',
            'brand' => 'Brand X',
            'category' => 'Category Y',
            'specifications' => 'High quality widgets',
            'keywords' => ['widget', 'awesome']
        ]);

        $response = $this->get('/llms.txt');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $response->assertSee('Awesome Product A');
        $response->assertSee('Brand: Brand X');
    }

    public function test_sitemap_xml_index_endpoint()
    {
        $response = $this->get('/sitemap.xml');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/xml');
        $response->assertSee('sitemap-static.xml');
        $response->assertSee('sitemap-products.xml');
    }

    public function test_sitemap_static_xml_endpoint()
    {
        $response = $this->get('/sitemap-static.xml');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/xml');
        $response->assertSee('https://app.huntr.id/');
        $response->assertSee('https://app.huntr.id/catalogues');
    }

    public function test_sitemap_products_xml_endpoint()
    {
        $company = Company::create([
            'name' => 'Test Vendor Corp',
            'type' => 'vendor',
            'status' => 'approved',
            'owner_id' => null
        ]);

        $catalogue = Catalogue::create([
            'company_id' => $company->id,
            'item_code' => 'CODE-123',
            'name' => 'Awesome Product A',
            'brand' => 'Brand X',
            'category' => 'Category Y',
            'specifications' => 'High quality widgets',
            'keywords' => ['widget', 'awesome'],
            'image_path' => 'catalogues/widget.png'
        ]);

        $response = $this->get('/sitemap-products.xml');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/xml');
        $response->assertSee($catalogue->id);
        $response->assertSee('Awesome Product A');
    }

    public function test_product_seo_api_endpoint()
    {
        $company = Company::create([
            'name' => 'Test Vendor Corp',
            'type' => 'vendor',
            'status' => 'approved',
            'owner_id' => null
        ]);

        $catalogue = Catalogue::create([
            'company_id' => $company->id,
            'item_code' => 'CODE-123',
            'name' => 'Awesome Product A',
            'brand' => 'Brand X',
            'category' => 'Category Y',
            'specifications' => 'High quality widgets',
            'keywords' => ['widget', 'awesome']
        ]);

        $response = $this->get("/api/catalogues/{$catalogue->id}/seo");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'id',
                'title',
                'meta_description',
                'meta_keywords',
                'canonical_url',
                'image_url',
                'schema_markup' => [
                    '@context',
                    '@type',
                    'name',
                    'brand',
                    'offers' => [
                        '@type',
                        'priceCurrency',
                        'price',
                        'seller'
                    ]
                ]
            ]
        ]);
        
        $data = $response->json('data');
        $this->assertEquals("Buy Awesome Product A - Brand X | Huntr", $data['title']);
        $this->assertStringContainsString("Test Vendor Corp", $data['meta_description']);
    }

    public function test_artisan_command_generates_files()
    {
        $company = Company::create([
            'name' => 'Test Vendor Corp',
            'type' => 'vendor',
            'status' => 'approved',
            'owner_id' => null
        ]);


        Catalogue::create([
            'company_id' => $company->id,
            'item_code' => 'CODE-123',
            'name' => 'Awesome Product A',
            'brand' => 'Brand X',
            'category' => 'Category Y',
            'specifications' => 'High quality widgets',
            'keywords' => ['widget', 'awesome']
        ]);

        // Delete any existing generated files in public/ to avoid false positives
        @unlink(public_path('sitemap.xml'));
        @unlink(public_path('sitemap-static.xml'));
        @unlink(public_path('sitemap-products.xml'));
        @unlink(public_path('llms.txt'));

        $this->artisan('seo:generate-sitemaps')
            ->assertExitCode(0);

        $this->assertFileExists(public_path('sitemap.xml'));
        $this->assertFileExists(public_path('sitemap-static.xml'));
        $this->assertFileExists(public_path('sitemap-products.xml'));
        $this->assertFileExists(public_path('llms.txt'));
        $this->assertFileExists(public_path('robots.txt'));
    }
}

