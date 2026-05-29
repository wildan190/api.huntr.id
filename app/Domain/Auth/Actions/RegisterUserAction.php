<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Auth\Repositories\UserRepositoryInterface;
use App\Domain\Auth\Models\User;

class RegisterUserAction
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository
    ) {}

    /**
     * Register a new user in the system.
     *
     * @param array $data Input fields: name, email, password, role
     * @return User
     */
    public function execute(array $data): User
    {
        return $this->userRepository->create($data);
    }
}
