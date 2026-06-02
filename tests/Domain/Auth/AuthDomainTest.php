<?php

namespace Tests\Domain\Auth;

use App\Domain\Auth\Models\User;
use App\Support\OtpStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthDomainTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function user_can_login_with_valid_credentials()
    {
        $user = User::factory()->create([
            'email' => 'test@huntr.id',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'test@huntr.id',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['user' => ['token', 'name', 'role']]);
    }

    /** @test */
    public function user_can_request_otp()
    {
        $response = $this->postJson('/api/auth/otp/send', [
            'whatsapp' => '08123456789',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['message', 'otp_token']);
    }

    /** @test */
    public function user_can_verify_otp()
    {
        // Mock sending OTP
        $this->postJson('/api/auth/otp/send', [
            'whatsapp' => '08123456789',
        ]);

        // Get the real OTP from store (testing only)
        $otp = \Illuminate\Support\Facades\DB::table('whatsapp_otp_codes')
            ->where('whatsapp', '628123456789')
            ->value('code');

        $response = $this->postJson('/api/auth/otp/verify', [
            'whatsapp' => '08123456789',
            'otp' => (string) $otp,
        ]);

        $response->assertStatus(200)
            ->assertJson(['message' => 'Nomor WhatsApp berhasil diverifikasi.']);
    }
}
