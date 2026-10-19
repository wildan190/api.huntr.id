<?php

namespace App\Domain\Subscription\Services;

use App\Domain\Auth\Models\User;
use App\Domain\Company\Models\Company;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PricingAnnouncementService
{
    private const CODE = 'enterprise-pricing-v1';

    public function current(Company $company, User $user): ?array
    {
        $announcement = $this->eligibleAnnouncement($company, $user);
        if (! $announcement || $this->readQuery($announcement->id, $company, $user)->exists()) {
            return null;
        }

        return [
            'id' => $announcement->id,
            'code' => $announcement->code,
            'published_at' => Carbon::parse($announcement->published_at)->toIso8601String(),
        ];
    }

    public function acknowledge(Company $company, User $user, string $id): bool
    {
        $announcement = $this->eligibleAnnouncement($company, $user);
        if (! $announcement || $announcement->id !== $id) {
            return false;
        }

        $now = now();
        DB::table('enterprise_pricing_announcement_reads')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'announcement_id' => $announcement->id,
            'company_id' => $company->id,
            'user_id' => $user->id,
            'acknowledged_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return true;
    }

    private function eligibleAnnouncement(Company $company, User $user): ?object
    {
        if (! in_array($company->type, ['buyer', 'vendor'], true)) {
            return null;
        }

        $announcement = DB::table('enterprise_pricing_announcements')
            ->where('code', self::CODE)
            ->where('published_at', '<=', now())
            ->first();
        if (! $announcement) {
            return null;
        }

        $publishedAt = Carbon::parse($announcement->published_at);
        if (($user->created_at && $user->created_at->gt($publishedAt)) ||
            ($company->created_at && $company->created_at->gt($publishedAt))) {
            return null;
        }

        return $announcement;
    }

    private function readQuery(string $id, Company $company, User $user)
    {
        return DB::table('enterprise_pricing_announcement_reads')
            ->where('announcement_id', $id)
            ->where('company_id', $company->id)
            ->where('user_id', $user->id);
    }
}
