<?php

namespace App\Domain\Auth\Repositories;

use App\Domain\Auth\Models\User;

interface UserRepositoryInterface
{
    /**
     * Create a new user record.
     *
     * @param array $data
     * @return User
     */
    public function create(array $data): User;

    /**
     * Find a user by their email address or WhatsApp number.
     *
     * @param string $login
     * @return User|null
     */
    public function findByEmailOrWhatsapp(string $login): ?User;

    /**
     * Find a user by their WhatsApp number.
     *
     * @param string $whatsapp
     * @return User|null
     */
    public function findByWhatsapp(string $whatsapp): ?User;

    /**
     * Update user attributes.
     *
     * @param User $user
     * @param array $data
     * @return User
     */
    public function update(User $user, array $data): User;
}
