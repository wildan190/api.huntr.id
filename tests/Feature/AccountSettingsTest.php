<?php

namespace Tests\Feature;

use App\Domain\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccountSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_change_password()
    {
        $user = User::factory()->create([
            'password' => Hash::make('old-password'),
        ]);

        $response = $this->actingAs($user)->putJson('/api/account/password', [
            'current_password' => 'old-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response->assertStatus(200);
        $this->assertTrue(Hash::check('new-password', $user->refresh()->password));
    }

    public function test_user_cannot_change_password_with_wrong_current_password()
    {
        $user = User::factory()->create([
            'password' => Hash::make('old-password'),
        ]);

        $response = $this->actingAs($user)->putJson('/api/account/password', [
            'current_password' => 'wrong-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['current_password']);
    }

    public function test_user_can_change_whatsapp_number_with_otp()
    {
        $user = User::factory()->create([
            'whatsapp' => '08123456789',
        ]);

        $newWhatsapp = '08987654321';
        Cache::put('otp_verified_' . $newWhatsapp, true, now()->addMinutes(15));

        $response = $this->actingAs($user)->putJson('/api/account/whatsapp', [
            'whatsapp' => $newWhatsapp,
        ]);

        $response->assertStatus(200);
        $this->assertEquals($newWhatsapp, $user->refresh()->whatsapp);
        $this->assertNull(Cache::get('otp_verified_' . $newWhatsapp));
    }

    public function test_user_cannot_change_whatsapp_number_without_otp()
    {
        $user = User::factory()->create([
            'whatsapp' => '08123456789',
        ]);

        $newWhatsapp = '08987654321';

        $response = $this->actingAs($user)->putJson('/api/account/whatsapp', [
            'whatsapp' => $newWhatsapp,
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'Nomor WhatsApp baru belum terverifikasi dengan OTP.']);
    }

    public function test_user_can_list_sessions()
    {
        $user = User::factory()->create();
        
        // Mock a session in the database
        DB::table('sessions')->insert([
            'id' => 'session_id_1',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Mozilla/5.0',
            'payload' => 'base64_payload',
            'last_activity' => time(),
        ]);

        $response = $this->actingAs($user)->getJson('/api/account/sessions');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'sessions' => [
                '*' => [
                    'id',
                    'ip_address',
                    'user_agent',
                    'is_current_device',
                    'last_active',
                ],
            ],
        ]);
    }

    public function test_user_can_logout_session()
    {
        $user = User::factory()->create();
        
        DB::table('sessions')->insert([
            'id' => 'session_id_to_delete',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Mozilla/5.0',
            'payload' => 'base64_payload',
            'last_activity' => time(),
        ]);

        $response = $this->actingAs($user)->deleteJson('/api/account/sessions/session_id_to_delete');

        $response->assertStatus(200);
        $this->assertDatabaseMissing('sessions', ['id' => 'session_id_to_delete']);
    }
}
