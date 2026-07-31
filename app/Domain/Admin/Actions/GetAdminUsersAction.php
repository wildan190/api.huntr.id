<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Auth\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

class GetAdminUsersAction
{
    /**
     * Get paginated list of all users with optional search filter.
     *
     * @param  array  $filters  Accepted keys: search
     * @param  int    $perPage
     * @return array{total: int, users: LengthAwarePaginator}
     */
    public function execute(array $filters = [], int $perPage = 10): array
    {
        $query = User::with(['roles', 'company'])
            ->orderBy('created_at', 'desc');

        if (!empty($filters['search'])) {
            $search = $filters['search'];

            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('email', 'ilike', "%{$search}%")
                  ->orWhere('whatsapp', 'ilike', "%{$search}%")
                  ->orWhereHas('company', function ($c) use ($search) {
                      $c->where('name', 'ilike', "%{$search}%");
                  });
            });
        }

        $total = User::count();
        $users = $query->paginate($perPage);

        return [
            'total' => $total,
            'users' => $users,
        ];
    }
}
