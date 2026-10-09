<?php

namespace Tests\Feature;

use App\Domain\Auth\Models\User;
use App\Domain\Company\Models\Company;
use App\Domain\Communication\Notifications\DatabaseNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationSecurityTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function anonymous_cannot_access_notifications_index()
    {
        $response = $this->getJson('/api/notifications');
        $response->assertStatus(401);
    }

    /** @test */
    public function anonymous_cannot_mark_notification_read()
    {
        $response = $this->postJson('/api/notifications/fake-id/read', []);
        $response->assertStatus(401);
    }

    /** @test */
    public function anonymous_cannot_mark_all_read()
    {
        $response = $this->postJson('/api/notifications/read-all', []);
        $response->assertStatus(401);
    }

    /** @test */
    public function anonymous_cannot_clear_all_notifications()
    {
        $response = $this->postJson('/api/notifications/clear-all', []);
        $response->assertStatus(401);
    }

    /** @test */
    public function anonymous_cannot_refresh_whatsapp_token()
    {
        $response = $this->postJson('/api/communication/whatsapp/refresh-token', []);
        $response->assertStatus(401);
    }

    /** @test */
    public function user_can_access_own_notifications()
    {
        $userA = User::factory()->create();

        $userA->notify(new DatabaseNotification('Test A', 'Body A', '/test-a'));
        $userA->notify(new DatabaseNotification('Test B', 'Body B', '/test-b'));

        $response = $this->actingAs($userA, 'api')->getJson('/api/notifications');
        $response->assertStatus(200);
        $response->assertJsonStructure(['data', 'current_page', 'per_page', 'total', 'last_page']);

        $titles = collect($response->json('data'))->pluck('data.title')->values();
        $this->assertContains('Test A', $titles->toArray());
        $this->assertContains('Test B', $titles->toArray());
    }

    /** @test */
    public function user_a_cannot_access_user_b_notifications_using_user_id()
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $userB->notify(new DatabaseNotification('UserB Secret', 'Only for B', '/secret'));

        $response = $this->actingAs($userA, 'api')->getJson("/api/notifications?user_id={$userB->id}");
        $response->assertStatus(403);
    }

    /** @test */
    public function user_a_cannot_mark_user_b_notification_read()
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $userB->notify(new DatabaseNotification('Secret', 'Body', '/url'));
        $notificationId = $userB->notifications()->first()->id;

        $response = $this->actingAs($userA, 'api')->postJson("/api/notifications/{$notificationId}/read", [
            'user_id' => $userB->id,
        ]);

        $response->assertStatus(403);
        $this->assertNull($userB->notifications()->first()->read_at);
    }

    /** @test */
    public function user_a_cannot_mark_all_user_b_notifications_read()
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $userB->notify(new DatabaseNotification('S1', 'B1', '/'));
        $userB->notify(new DatabaseNotification('S2', 'B2', '/'));

        $response = $this->actingAs($userA, 'api')->postJson('/api/notifications/read-all', [
            'user_id' => $userB->id,
        ]);
        $response->assertStatus(403);

        $this->assertEquals(2, $userB->fresh()->unreadNotifications()->count());
    }

    /** @test */
    public function user_a_cannot_clear_user_b_notifications()
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $userB->notify(new DatabaseNotification('Persist', 'Should stay', '/'));

        $response = $this->actingAs($userA, 'api')->postJson('/api/notifications/clear-all', [
            'user_id' => $userB->id,
        ]);
        $response->assertStatus(403);

        $this->assertEquals(1, $userB->fresh()->notifications()->count());
    }

    /** @test */
    public function user_cannot_access_company_b_notifications()
    {
        $userA = User::factory()->create();

        $ownerB = User::factory()->create();
        $companyB = Company::factory()->create([
            'owner_id' => $ownerB->id,
            'type' => 'vendor',
        ]);

        $companyB->notify(new DatabaseNotification('CompanyB Secret', 'Internal', '/internal'));

        $response = $this->actingAs($userA, 'api')->getJson("/api/notifications?company_id={$companyB->id}");
        $response->assertStatus(403);
    }

    /** @test */
    public function user_cannot_clear_company_b_notifications()
    {
        $userA = User::factory()->create();

        $ownerB = User::factory()->create();
        $companyB = Company::factory()->create([
            'owner_id' => $ownerB->id,
            'type' => 'vendor',
        ]);

        $companyB->notify(new DatabaseNotification('Keep', 'Preserve', '/'));

        $response = $this->actingAs($userA, 'api')->postJson('/api/notifications/clear-all', [
            'company_id' => $companyB->id,
        ]);
        $response->assertStatus(403);

        $this->assertEquals(1, $companyB->fresh()->notifications()->count());
    }

    /** @test */
    public function company_owner_can_access_own_company_notifications()
    {
        $owner = User::factory()->create();
        $company = Company::factory()->create([
            'owner_id' => $owner->id,
            'type' => 'buyer',
        ]);

        $owner->notify(new DatabaseNotification('Personal', 'Body', '/me'));
        $company->notify(new DatabaseNotification('Company-wide', 'Announcement', '/company'));

        $response = $this->actingAs($owner, 'api')->getJson("/api/notifications?company_id={$company->id}");
        $response->assertStatus(200);

        $titles = collect($response->json('data'))->pluck('data.title')->values()->toArray();
        $this->assertContains('Personal', $titles);
        $this->assertContains('Company-wide', $titles);
    }

    /** @test */
    public function company_member_can_access_member_company_notifications()
    {
        $user = User::factory()->create();
        $company = Company::factory()->create([
            'type' => 'vendor',
        ]);

        $user->update(['company_id' => $company->id]);

        $company->notify(new DatabaseNotification('Vendor Alert', 'For members', '/vendor'));

        $response = $this->actingAs($user, 'api')->getJson("/api/notifications?company_id={$company->id}");
        $response->assertStatus(200);

        $titles = collect($response->json('data'))->pluck('data.title')->values()->toArray();
        $this->assertContains('Vendor Alert', $titles);
    }

    /** @test */
    public function own_clear_all_still_works()
    {
        $user = User::factory()->create();

        $user->notify(new DatabaseNotification('N1', 'B1', '/'));
        $user->notify(new DatabaseNotification('N2', 'B2', '/'));
        $this->assertEquals(2, $user->fresh()->notifications()->count());

        $response = $this->actingAs($user, 'api')->postJson('/api/notifications/clear-all');
        $response->assertStatus(200);

        $this->assertEquals(0, $user->fresh()->notifications()->count());
    }

    /** @test */
    public function own_mark_all_read_still_works()
    {
        $user = User::factory()->create();

        $user->notify(new DatabaseNotification('R1', 'B1', '/'));
        $user->notify(new DatabaseNotification('R2', 'B2', '/'));
        $this->assertEquals(2, $user->fresh()->unreadNotifications()->count());

        $response = $this->actingAs($user, 'api')->postJson('/api/notifications/read-all');
        $response->assertStatus(200);

        $this->assertEquals(0, $user->fresh()->unreadNotifications()->count());
    }

    /** @test */
    public function own_mark_single_read_still_works()
    {
        $user = User::factory()->create();
        $user->notify(new DatabaseNotification('Single', 'Body', '/'));

        $notification = $user->notifications()->first();
        $this->assertNull($notification->read_at);

        $response = $this->actingAs($user, 'api')->postJson("/api/notifications/{$notification->id}/read");
        $response->assertStatus(200);

        $this->assertNotNull($notification->fresh()->read_at);
    }

    /** @test */
    public function user_index_without_client_user_id_still_works()
    {
        $user = User::factory()->create();
        $user->notify(new DatabaseNotification('AutoId', 'From auth', '/'));

        $response = $this->actingAs($user, 'api')->getJson('/api/notifications');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    /** @test */
    public function user_matching_user_id_is_allowed_backward_compat()
    {
        $user = User::factory()->create();
        $user->notify(new DatabaseNotification('Compat', 'OK', '/'));

        $response = $this->actingAs($user, 'api')->getJson("/api/notifications?user_id={$user->id}");
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }
}
