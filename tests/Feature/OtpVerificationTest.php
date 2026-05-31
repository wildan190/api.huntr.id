<?php

namespace Tests\Feature;

use App\Support\WhatsappNumber;
use App\Support\OtpStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OtpVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_otp_verify_accepts_equivalent_phone_formats(): void
    {
        $canonical = WhatsappNumber::normalize('085156334793');
        DB::table('whatsapp_otp_codes')->insert([
            'whatsapp' => $canonical,
            'code' => '123456',
            'expires_at' => now()->addMinutes(10),
            'created_at' => now(),
        ]);

        $response = $this->postJson('/api/auth/otp/verify', [
            'whatsapp' => '+62 851-563-347-93',
            'otp' => '123456',
        ]);

        $response->assertOk()
            ->assertJson(['verified' => true]);

        $this->assertTrue(OtpStore::isVerified($canonical));
    }

    public function test_register_accepts_otp_verified_with_different_phone_format(): void
    {
        $canonical = WhatsappNumber::normalize('085156334793');
        DB::table('whatsapp_otp_verified')->insert([
            'whatsapp' => $canonical,
            'expires_at' => now()->addMinutes(15),
            'verified_at' => now(),
        ]);

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Budi Santoso',
            'whatsapp' => '6285156334793',
            'password' => 'password123',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('users', [
            'whatsapp' => $canonical,
        ]);
    }

    public function test_otp_verify_rejects_invalid_code(): void
    {
        $canonical = WhatsappNumber::normalize('085156334793');
        DB::table('whatsapp_otp_codes')->insert([
            'whatsapp' => $canonical,
            'code' => '123456',
            'expires_at' => now()->addMinutes(10),
            'created_at' => now(),
        ]);

        $response = $this->postJson('/api/auth/otp/verify', [
            'whatsapp' => '085156334793',
            'otp' => '654321',
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'Kode OTP tidak sesuai. Periksa kembali kode dari WhatsApp.']);
    }

    public function test_resend_otp_reuses_existing_code(): void
    {
        $phone = '081111111111';

        $first = $this->postJson('/api/auth/otp/send', ['whatsapp' => $phone]);
        $first->assertOk();
        $otp1 = $first->json('otp');

        $second = $this->postJson('/api/auth/otp/send', ['whatsapp' => $phone]);
        $second->assertOk();
        $otp2 = $second->json('otp');

        $this->assertSame($otp1, $otp2);

        $verify = $this->postJson('/api/auth/otp/verify', [
            'whatsapp' => $phone,
            'otp' => $otp1,
        ]);

        $verify->assertOk()->assertJson(['verified' => true]);
    }

    public function test_send_rejects_invalid_phone_format(): void
    {
        $response = $this->postJson('/api/auth/otp/send', [
            'whatsapp' => '12345',
        ]);

        $response->assertStatus(422);
    }

    public function test_verify_with_otp_token_skips_whatsapp_mismatch(): void
    {
        $issued = \App\Support\OtpStore::issue('6285156334793');
        $token = $issued['token'];
        $otp = $issued['otp'];

        $response = $this->postJson('/api/auth/otp/verify', [
            'otp_token' => $token,
            'otp' => $otp,
        ]);

        $response->assertOk()
            ->assertJsonPath('whatsapp', '6285156334793');
    }

    public function test_send_returns_otp_token(): void
    {
        $response = $this->postJson('/api/auth/otp/send', [
            'whatsapp' => '085156334793',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['otp_token', 'whatsapp']);
    }

    public function test_typo_phone_685_normalizes_and_verifies(): void
    {
        $send = $this->postJson('/api/auth/otp/send', ['whatsapp' => '685156334793']);
        $send->assertOk()->assertJsonPath('whatsapp', '6285156334793');

        $verify = $this->postJson('/api/auth/otp/verify', [
            'otp_token' => $send->json('otp_token'),
            'otp' => $send->json('otp'),
        ]);

        $verify->assertOk()->assertJson(['verified' => true]);
    }
}
