<?php

namespace App\Domain\Auth\Repositories;

use App\Domain\Auth\Models\User;
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

        $user->assignRole($data['role'] ?? 'buyer');

        return $user;
    }

    /**
     * {@inheritdoc}
     */
    public function findByEmailOrWhatsapp(string $login): ?User
    {
        return User::where('email', $login)->orWhere('whatsapp', $login)->first();
    }

    /**
     * {@inheritdoc}
     */
    public function findByWhatsapp(string $whatsapp): ?User
    {
        return User::where('whatsapp', $whatsapp)->first();
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
