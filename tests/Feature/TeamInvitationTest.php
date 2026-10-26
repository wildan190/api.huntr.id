<?php

namespace Tests\Feature;

use App\Domain\Auth\Models\User;
use App\Domain\Communication\Mail\CompanyInvitationMail;
use App\Domain\Company\Models\Company;
use App\Domain\Company\Models\CompanyInvitation;
use App\Domain\Company\Models\CompanyRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeamInvitationTest extends TestCase
{
    use RefreshDatabase;

    protected Company $buyerCompany;
    protected User $ownerUser;
    protected User $invitedUser;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Create owner and buyer company
        $this->ownerUser = User::create([
            'name' => 'Owner Buyer',
            'email' => 'owner@buyercompany.com',
            'password' => bcrypt('password123'),
        ]);

        $this->buyerCompany = Company::create([
            'name' => 'PT Buyer Sejahtera',
            'type' => 'buyer',
            'status' => 'approved',
            'owner_id' => $this->ownerUser->id,
            'email' => 'contact@buyercompany.com',
            'country' => 'ID',
        ]);

        $this->ownerUser->update(['company_id' => $this->buyerCompany->id]);

        // 2. Create another user who will be invited
        $this->invitedUser = User::create([
            'name' => 'Member Baru',
            'email' => 'member@buyercompany.com',
            'password' => bcrypt('password123'),
        ]);
    }

    public function test_company_owner_can_invite_team_member(): void
    {
        Mail::fake();

        $this->actingAs($this->ownerUser, 'api');

        $response = $this->postJson('/api/companies/invite', [
            'company_id' => $this->buyerCompany->id,
            'email' => 'member@buyercompany.com',
            'whatsapp' => '081234567890',
            'role' => 'buyer',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'invitation' => [
                    'id',
                    'company_id',
                    'email',
                    'role',
                    'token',
                    'status',
                    'expires_at',
                ],
                'invite_url',
                'email',
                'message',
            ]);

        $this->assertDatabaseHas('company_invitations', [
            'company_id' => $this->buyerCompany->id,
            'email' => 'member@buyercompany.com',
            'whatsapp' => '081234567890',
            'role' => 'buyer',
            'status' => 'pending',
            'created_by' => $this->ownerUser->id,
        ]);

        Mail::assertQueued(CompanyInvitationMail::class, function ($mail) {
            return $mail->hasTo('member@buyercompany.com') &&
                   $mail->company->id === $this->buyerCompany->id &&
                   $mail->inviter->id === $this->ownerUser->id;
        });
    }

    public function test_public_can_get_invitation_info_by_valid_token(): void
    {
        $token = Str::random(32);

        CompanyInvitation::create([
            'company_id' => $this->buyerCompany->id,
            'email' => 'candidate@test.com',
            'whatsapp' => '089988776655',
            'role' => 'buyer',
            'token' => $token,
            'status' => 'pending',
            'expires_at' => now()->addDays(7),
            'created_by' => $this->ownerUser->id,
        ]);

        // No authentication required
        $response = $this->getJson("/api/invitations/info?token={$token}");

        $response->assertStatus(200)
            ->assertJson([
                'company' => 'PT Buyer Sejahtera',
                'email' => 'candidate@test.com',
                'whatsapp' => '089988776655',
                'role' => 'buyer',
            ]);
    }

    public function test_invitation_info_returns_404_for_invalid_token(): void
    {
        $response = $this->getJson('/api/invitations/info?token=non_existent_token');

        $response->assertStatus(404)
            ->assertJson([
                'message' => 'Invitation is invalid or has expired.',
            ]);
    }

    public function test_invitation_info_returns_404_for_expired_token(): void
    {
        $token = Str::random(32);

        CompanyInvitation::create([
            'company_id' => $this->buyerCompany->id,
            'email' => 'candidate@test.com',
            'role' => 'buyer',
            'token' => $token,
            'status' => 'pending',
            'expires_at' => now()->subDay(), // Expired
            'created_by' => $this->ownerUser->id,
        ]);

        $response = $this->getJson("/api/invitations/info?token={$token}");

        $response->assertStatus(404)
            ->assertJson([
                'message' => 'Invitation is invalid or has expired.',
            ]);
    }

    public function test_invited_user_can_accept_invitation(): void
    {
        $token = Str::random(32);

        $invitation = CompanyInvitation::create([
            'company_id' => $this->buyerCompany->id,
            'email' => $this->invitedUser->email,
            'role' => 'buyer',
            'token' => $token,
            'status' => 'pending',
            'expires_at' => now()->addDays(7),
            'created_by' => $this->ownerUser->id,
        ]);

        $this->actingAs($this->invitedUser, 'api');

        $response = $this->postJson('/api/companies/accept-invitation', [
            'token' => $token,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('company.id', $this->buyerCompany->id)
            ->assertJsonPath('user.company_id', $this->buyerCompany->id);

        // Verify user relationship and roles
        $this->invitedUser->refresh();
        $this->assertEquals($this->buyerCompany->id, $this->invitedUser->company_id);
        $this->assertTrue($this->invitedUser->hasRole('buyer'));

        // Verify invitation status updated to accepted
        $this->assertDatabaseHas('company_invitations', [
            'id' => $invitation->id,
            'status' => 'accepted',
        ]);
    }

    public function test_cannot_accept_already_accepted_invitation(): void
    {
        $token = Str::random(32);

        CompanyInvitation::create([
            'company_id' => $this->buyerCompany->id,
            'email' => $this->invitedUser->email,
            'role' => 'buyer',
            'token' => $token,
            'status' => 'accepted', // already accepted
            'expires_at' => now()->addDays(7),
            'created_by' => $this->ownerUser->id,
        ]);

        $this->actingAs($this->invitedUser, 'api');

        $response = $this->postJson('/api/companies/accept-invitation', [
            'token' => $token,
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'message' => 'Invitation is invalid or has expired.',
            ]);
    }

    public function test_cannot_accept_expired_invitation(): void
    {
        $token = Str::random(32);

        CompanyInvitation::create([
            'company_id' => $this->buyerCompany->id,
            'email' => $this->invitedUser->email,
            'role' => 'buyer',
            'token' => $token,
            'status' => 'pending',
            'expires_at' => now()->subHour(), // Expired
            'created_by' => $this->ownerUser->id,
        ]);

        $this->actingAs($this->invitedUser, 'api');

        $response = $this->postJson('/api/companies/accept-invitation', [
            'token' => $token,
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'message' => 'Invitation is invalid or has expired.',
            ]);
    }

    public function test_unauthorized_user_without_team_manage_permission_cannot_invite(): void
    {
        // A user from outside the company
        $stranger = User::create([
            'name' => 'Stranger',
            'email' => 'stranger@other.com',
            'password' => bcrypt('password123'),
        ]);

        $this->actingAs($stranger, 'api');

        $response = $this->postJson('/api/companies/invite', [
            'company_id' => $this->buyerCompany->id,
            'email' => 'someone@other.com',
            'role' => 'buyer',
        ]);

        $response->assertStatus(403);
    }

    public function test_cannot_invite_with_invalid_role_for_buyer_company(): void
    {
        $this->actingAs($this->ownerUser, 'api');

        $response = $this->postJson('/api/companies/invite', [
            'company_id' => $this->buyerCompany->id,
            'email' => 'test@buyer.com',
            'role' => 'super-admin', // Invalid role for buyer company
        ]);

        $response->assertStatus(403);
    }

    public function test_vendor_company_owner_can_invite_team_member(): void
    {
        Mail::fake();

        $vendorOwner = User::create([
            'name' => 'Owner Vendor',
            'email' => 'vendor_owner@supplier.com',
            'password' => bcrypt('password123'),
        ]);

        $vendorCompany = Company::create([
            'name' => 'PT Supplier Hebat',
            'type' => 'vendor',
            'status' => 'approved',
            'owner_id' => $vendorOwner->id,
            'email' => 'contact@supplier.com',
            'country' => 'ID',
        ]);

        $vendorOwner->update(['company_id' => $vendorCompany->id]);

        $this->actingAs($vendorOwner, 'api');

        $response = $this->postJson('/api/companies/invite', [
            'company_id' => $vendorCompany->id,
            'email' => 'staff@supplier.com',
            'role' => 'finance',
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('company_invitations', [
            'company_id' => $vendorCompany->id,
            'email' => 'staff@supplier.com',
            'role' => 'finance',
            'status' => 'pending',
        ]);

        Mail::assertQueued(CompanyInvitationMail::class, function ($mail) use ($vendorCompany) {
            return $mail->hasTo('staff@supplier.com') &&
                   $mail->company->id === $vendorCompany->id;
        });
    }

    public function test_invited_user_can_accept_invitation_with_custom_company_role(): void
    {
        $accessService = app(\App\Domain\Company\Services\CompanyAccessService::class);
        $accessService->bootstrap($this->buyerCompany);

        // Fetch one of the bootstrapped company roles (e.g., procurement_officer)
        $companyRole = CompanyRole::where('company_id', $this->buyerCompany->id)
            ->where('slug', 'procurement_officer')
            ->firstOrFail();

        $token = Str::random(32);

        $invitation = CompanyInvitation::create([
            'company_id' => $this->buyerCompany->id,
            'company_role_id' => $companyRole->id,
            'email' => $this->invitedUser->email,
            'role' => 'buyer',
            'token' => $token,
            'status' => 'pending',
            'expires_at' => now()->addDays(7),
            'created_by' => $this->ownerUser->id,
        ]);

        $this->actingAs($this->invitedUser, 'api');

        $response = $this->postJson('/api/companies/accept-invitation', [
            'token' => $token,
        ]);

        $response->assertStatus(200);

        // Verify company_user_roles has the assignment
        $this->assertDatabaseHas('company_user_roles', [
            'company_id' => $this->buyerCompany->id,
            'user_id' => $this->invitedUser->id,
            'company_role_id' => $companyRole->id,
        ]);
    }
}
