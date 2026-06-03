<?php

namespace App\Domain\Company\Actions;

use App\Domain\Company\Models\Company;
use App\Domain\Company\Models\CompanyInvitation;
use App\Domain\Auth\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class InviteUserAction
{
    /**
     * Create a new company invitation and return the WhatsApp link.
     */
    public function execute(User $inviter, array $data): array
    {
        $company = Company::findOrFail($data['company_id']);
        
        // Ensure the inviter is the owner or a manager
        if ($company->owner_id !== $inviter->id && !$inviter->hasRole('manager')) {
            throw new \Exception("Unauthorized to invite users to this company.");
        }

        $token = Str::random(32);
        
        $invitation = CompanyInvitation::create([
            'company_id' => $company->id,
            'whatsapp'   => $data['whatsapp'],
            'email'      => $data['email'] ?? null,
            'role'       => $data['role'],
            'token'      => $token,
            'status'     => 'pending',
            'expires_at' => now()->addDays(7),
            'created_by' => $inviter->id,
        ]);

        $inviteUrl = config('app.frontend_url', 'http://localhost:5173') . "/invite/accept?token=" . $token;
        
        $message = "Halo! Anda diundang untuk bergabung dengan perusahaan {$company->name} di Huntr.id sebagai {$data['role']}.\n\nSilakan klik tautan berikut untuk menerima undangan:\n{$inviteUrl}";
        
        $whatsappLink = "https://wa.me/" . preg_replace('/[^0-9]/', '', $data['whatsapp']) . "?text=" . urlencode($message);

        return [
            'invitation' => $invitation,
            'whatsapp_link' => $whatsappLink,
            'message' => $message,
        ];
    }
}
