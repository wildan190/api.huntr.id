<?php

namespace Tests\Domain\Company;

use App\Domain\Auth\Models\User;
use App\Domain\Company\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyDomainTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function authenticated_user_can_get_their_companies()
    {
        $user = User::factory()->create();
        $company = Company::factory()->create(['owner_id' => $user->id]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/companies/my');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'companies');
    }

    /** @test */
    public function authenticated_user_can_register_a_company()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/companies', [
                'name' => 'PT Test Indonesia',
                'type' => 'buyer',
                'tax_id' => '012345678901234',
                'email' => 'contact@test.id',
                'documents' => [
                    [
                        'name' => 'NPWP',
                        'type' => 'NPWP',
                        'file_path' => 'docs/test-npwp.pdf'
                    ]
                ]
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('company.name', 'PT Test Indonesia');
    }
}
