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

        if ($company->owner_id !== $inviter->id && !$inviter->hasRole('manager')) {
            throw new \Exception("Unauthorized to invite users to this company.");
        }

        $this->validateRoleForCompanyType($data['role'], $company->type);

        $token = Str::random(32);

        $invitation = CompanyInvitation::create([
            'company_id' => $company->id,
            'whatsapp' => $data['whatsapp'],
            'email' => $data['email'] ?? null,
            'role' => $data['role'],
            'token' => $token,
            'status' => 'pending',
            'expires_at' => now()->addDays(7),
            'created_by' => $inviter->id,
        ]);

        $inviteUrl = config('app.frontend_url', 'http://localhost:5173') . "/invite/accept?token=" . $token;

        $message = "Hello! You are invited to join {$company->name} on Huntr.id as a {$data['role']}.\n\nPlease click the following link to accept the invitation:\n{$inviteUrl}";

        $whatsappLink = "https://wa.me/" . preg_replace('/[^0-9]/', '', $data['whatsapp']) . "?text=" . urlencode($message);

        return [
            'invitation' => $invitation,
            'whatsapp_link' => $whatsappLink,
            'message' => $message,
        ];
    }

    /**
     * Validate that the role is appropriate for the company type.
     */
    private function validateRoleForCompanyType(string $role, string $companyType): void
    {
        $buyerRoles = ['buyer', 'manager', 'finance'];
        $vendorRoles = ['admin', 'manager', 'finance', 'buyer'];

        if ($companyType === 'buyer' && !in_array($role, $buyerRoles)) {
            throw new \Exception("Role '{$role}' is not valid for buyer companies. Valid roles: " . implode(', ', $buyerRoles));
        }

        if ($companyType === 'vendor' && !in_array($role, $vendorRoles)) {
            throw new \Exception("Role '{$role}' is not valid for vendor companies. Valid roles: " . implode(', ', $vendorRoles));
        }
    }
}
