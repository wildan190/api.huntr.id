<?php

namespace App\Domain\Company\Actions;

use App\Domain\Company\Models\Company;
use App\Domain\Company\Models\CompanyInvitation;
use App\Domain\Auth\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use App\Domain\Communication\Mail\CompanyInvitationMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;

class InviteUserAction
{
    /**
     * Create a new company invitation and dispatch invitation email.
     */
    public function execute(User $inviter, array $data): array
    {
        $company = Company::findOrFail($data['company_id']);

        if ($company->owner_id !== $inviter->id && !$inviter->hasRole('manager')) {
            throw new \Exception("Unauthorized to invite users to this company.");
        }

        $this->validateRoleForCompanyType($data['role'], $company->type, $company->id);

        $token = Str::random(32);

        $invitation = CompanyInvitation::create([
            'company_id' => $company->id,
            'email' => $data['email'],
            'whatsapp' => $data['whatsapp'] ?? null,
            'role' => $data['role'],
            'token' => $token,
            'status' => 'pending',
            'expires_at' => now()->addDays(7),
            'created_by' => $inviter->id,
        ]);

        $inviteUrl = rtrim(config('app.frontend_url', 'http://localhost:5173'), '/') . "/invite/accept?token=" . $token;

        try {
            Mail::to($invitation->email)->send(new CompanyInvitationMail($company, $inviter, $invitation));
            Log::info("Company invitation email dispatched to {$invitation->email} for company {$company->name}");
        } catch (\Throwable $e) {
            Log::error("Failed to dispatch invitation email to {$invitation->email}: " . $e->getMessage());
        }

        return [
            'invitation' => $invitation,
            'invite_url' => $inviteUrl,
            'email' => $invitation->email,
            'message' => "Undangan berhasil dikirim ke email {$invitation->email}.",
        ];
    }

    /**
     * Validate that the role is appropriate for the company type.
     */
    private function validateRoleForCompanyType(string $role, string $companyType, ?string $companyId = null): void
    {
        $buyerRoles = ['buyer', 'manager', 'finance'];
        if ($companyId && DB::table('company_apps')->where('company_id', $companyId)->where('app_key', 'wms-inventory')->whereNotNull('installed_at')->exists()) {
            $buyerRoles[] = 'warehouse_admin';
        }
        $vendorRoles = ['admin', 'manager', 'finance', 'buyer'];

        if ($companyType === 'buyer' && !in_array($role, $buyerRoles)) {
            throw new \Exception("Role '{$role}' is not valid for buyer companies. Valid roles: " . implode(', ', $buyerRoles));
        }

        if ($companyType === 'vendor' && !in_array($role, $vendorRoles)) {
            throw new \Exception("Role '{$role}' is not valid for vendor companies. Valid roles: " . implode(', ', $vendorRoles));
        }
    }
}
