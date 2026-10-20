<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Domain\Company\Models\Company;
use Symfony\Component\HttpFoundation\Response;

class CheckCompanyApproved
{
    /**
     * Endpoints that are part of the onboarding/setup flow and should NEVER be blocked,
     * regardless of company status.
     */
    protected array $onboardingPaths = [
        'api/companies/verify-npwp',
        'api/companies/documents/upload',
        'api/companies/logo/upload',
    ];

    /**
     * Endpoints that require a fully APPROVED (verified) company.
     * Starter / pending companies are blocked from these.
     *
     * Pattern: exact path OR regex-like prefix check (ends with *)
     */
    protected array $verifiedOnlyPaths = [
        'api/rfqs',                  // Create RFQ (POST)
        'api/proposals',             // Submit bid/proposal (POST)
        'api/purchase-orders',       // Create PO (POST)
        'api/crowd-buy',             // Create crowd-buy campaign (POST)
        'api/crowd-buy/*/bids',      // Bid on crowd-buy (POST)
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $currentPath = trim($request->path(), '/');

        // --- Always allow onboarding endpoints ---
        foreach ($this->onboardingPaths as $onboardingPath) {
            if ($currentPath === $onboardingPath) {
                return $next($request);
            }
        }

        // --- Resolve which company we're operating on ---
        $company = $this->resolveCompany($request, $currentPath);

        if (! $company) {
            return $next($request);
        }

        // --- Block REJECTED companies from state-changing actions (same as before) ---
        if ($company->status === 'rejected' && ! $request->isMethod('GET')) {
            return response()->json([
                'message' => 'Akun perusahaan Anda telah ditolak. Hubungi admin untuk informasi lebih lanjut.',
                'code'    => 'COMPANY_REJECTED',
            ], 403);
        }

        // --- Block STARTER / PENDING companies from verified-only endpoints ---
        if (! $company->isVerified() && $request->isMethod('POST')) {
            foreach ($this->verifiedOnlyPaths as $restricted) {
                if ($this->pathMatches($currentPath, $restricted)) {
                    return response()->json([
                        'message' => 'Akses fitur ini memerlukan verifikasi perusahaan. '
                            . 'Lengkapi NPWP dan dokumen legalitas Anda untuk melanjutkan.',
                        'code'              => 'COMPANY_NOT_VERIFIED',
                        'status'            => $company->status,
                        'verification_tier' => $company->verification_tier,
                        'profile_completeness' => $company->profile_completeness,
                    ], 403);
                }
            }
        }

        return $next($request);
    }

    // ---------------------------------------------------------------------------

    /**
     * Try to resolve which Company the request is targeting.
     */
    private function resolveCompany(Request $request, string $currentPath): ?Company
    {
        // 1. Extract UUID from URL: /api/companies/{uuid}/...
        $pathSegments = explode('/', $currentPath);
        if (
            count($pathSegments) >= 3
            && $pathSegments[0] === 'api'
            && $pathSegments[1] === 'companies'
            && $this->looksLikeUuid($pathSegments[2] ?? '')
        ) {
            $company = Company::find($pathSegments[2]);
            if ($company) {
                return $company;
            }
        }

        // 2. Check X-Company-Id header or request body / query string
        $companyId = $request->header('X-Company-Id')
            ?? $request->input('company_id')
            ?? $request->query('company_id');

        if ($companyId) {
            return Company::find($companyId);
        }

        return null;
    }

    /**
     * Match path against a pattern that may end with * (prefix wildcard).
     */
    private function pathMatches(string $currentPath, string $pattern): bool
    {
        if (str_ends_with($pattern, '*')) {
            $prefix = rtrim($pattern, '*');
            return str_starts_with($currentPath, $prefix);
        }

        return $currentPath === $pattern;
    }

    private function looksLikeUuid(string $value): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $value
        );
    }
}
