<?php

namespace App\Domain\Auth\Services;

use App\Domain\Auth\Models\User;
use App\Support\WhatsappNumber;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Fortify;

/**
 * AuthService
 * 
 * Tanggung jawab: Mengabstraksi logika autentikasi tingkat tinggi.
 * Menjembatani Laravel Fortify dengan Domain Auth.
 */
class AuthService
{
    /**
     * Mencari user berdasarkan email atau nomor WhatsApp yang sudah dinormalisasi.
     * 
     * @param string $login
     * @param string $password
     * @return User|null
     */
    public function attempt(string $login, string $password): ?User
    {
        $lookupLogin = $login;
        
        // Cek jika input adalah nomor WhatsApp
        if (preg_match('/^[0-9+ \-]+$/', $login)) {
            $lookupLogin = WhatsappNumber::normalize($login);
        }

        $user = User::where('email', $login)
                    ->orWhere('whatsapp', $lookupLogin)
                    ->first();

        if ($user && Hash::check($password, $user->password)) {
            return $user;
        }

        return null;
    }

    /**
     * Mendapatkan username yang digunakan Fortify.
     */
    public function getFortifyUsername(): string
    {
        return Fortify::username();
    }
}
