<?php

namespace App\Domain\Auth\Repositories;

use App\Domain\Auth\Models\User;
use App\Support\WhatsappNumber;
use Illuminate\Support\Facades\Hash;

class EloquentUserRepository implements UserRepositoryInterface
{
    /**
     * {@inheritdoc}
     */
    public function create(array $data): User
    {
        $user = User::create([
            'name'     => $data['name'],
            'email'    => $data['email'] ?? null,
            'whatsapp' => $data['whatsapp'] ?? null,
            'password' => Hash::make($data['password']),
        ]);

        $user->assignRole($data['role'] ?? 'manager');

        return $user;
    }

    /**
     * {@inheritdoc}
     */
    public function findByEmailOrWhatsapp(string $login): ?User
    {
        $normalizedWhatsapp = WhatsappNumber::normalize($login);

        return User::where('email', $login)
            ->orWhere('whatsapp', $login)
            ->when($normalizedWhatsapp, fn ($query) => $query->orWhere('whatsapp', $normalizedWhatsapp))
            ->first();
    }

    /**
     * {@inheritdoc}
     */
    public function findByWhatsapp(string $whatsapp): ?User
    {
        $normalized = WhatsappNumber::normalize($whatsapp);

        return User::where('whatsapp', $whatsapp)
            ->when($normalized, fn ($query) => $query->orWhere('whatsapp', $normalized))
            ->first();
    }

    /**
     * {@inheritdoc}
     */
    public function update(User $user, array $data): User
    {
        $user->update($data);

        return $user->fresh();
    }
}
