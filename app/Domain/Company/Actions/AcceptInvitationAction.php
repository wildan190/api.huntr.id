<?php

namespace App\Domain\Company\Actions;

use App\Domain\Company\Models\CompanyInvitation;
use App\Domain\Auth\Models\User;
use Illuminate\Support\Facades\Log;

class AcceptInvitationAction
{
    /**
     * Accept a company invitation and associate the user with the company and role.
     */
    public function execute(User $user, string $token): array
    {
        $invitation = CompanyInvitation::where('token', $token)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->firstOrFail();

        // Update user's company and role
        $user->update([
            'company_id' => $invitation->company_id,
            'role'       => $invitation->role,
        ]);

        // Mark invitation as accepted
        $invitation->update(['status' => 'accepted']);

        return [
            'user' => $user->fresh(),
            'company' => $invitation->company,
        ];
    }
}
