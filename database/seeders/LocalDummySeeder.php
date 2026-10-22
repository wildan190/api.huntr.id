<?php

namespace Database\Seeders;

use App\Domain\Access\Models\Role;
use App\Domain\Auth\Models\User;
use App\Domain\Company\Models\Company;
use App\Domain\Subscription\Models\CompanySubscription;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * LocalDummySeeder
 *
 * Seed data dummy untuk pengembangan lokal:
 *  - 1 akun buyer  : Wildan Jahidson Belfiore  (wildanbelfiore@aol.com)
 *  - 1 akun vendor : Anggi Septiani            (anggiseptiani22@outlook.com)
 *  - Perusahaan buyer & vendor (status: approved)
 *
 * ⚠️  GUARD: Seeder ini TIDAK AKAN BERJALAN di production.
 *     Guard dicek via APP_URL — jika mengandung "api.huntr.id" maka ditolak.
 *
 * Cara menjalankan:
 *     php artisan db:seed --class=LocalDummySeeder
 */
class LocalDummySeeder extends Seeder
{
    // ---------------------------------------------------------------
    // Guard: tolak jika production
    // ---------------------------------------------------------------
    private function isProduction(): bool
    {
        $url = config('app.url', '');

        return str_contains($url, 'api.huntr.id')
            || (str_contains($url, 'huntr.id')
                && ! str_contains($url, 'localhost')
                && ! str_contains($url, '127.0.0.1'));
    }

    // ---------------------------------------------------------------
    // Main
    // ---------------------------------------------------------------
    public function run(): void
    {
        if ($this->isProduction()) {
            $this->command->error(
                '🚫 LocalDummySeeder DITOLAK: Tidak boleh dijalankan di environment production!'
            );
            return;
        }

        $this->command->info('🌱 Memulai LocalDummySeeder...');

        // Pastikan roles sudah ada terlebih dahulu
        $this->call(AccessControlSeeder::class);

        $buyer  = $this->seedBuyer();
        $vendor = $this->seedVendor();

        $buyerCompany = $this->seedBuyerCompany($buyer);
        $this->seedVendorCompany($vendor);
        $this->fixBuyerSubscription($buyerCompany);

        $this->command->info('');
        $this->command->info('✅ LocalDummySeeder selesai!');
        $this->command->table(
            ['Role', 'Nama', 'Email', 'Password'],
            [
                ['Buyer',  $buyer->name,  $buyer->email,  'password'],
                ['Vendor', $vendor->name, $vendor->email, 'password'],
            ]
        );
    }

    // ---------------------------------------------------------------
    // Akun Buyer
    // ---------------------------------------------------------------
    private function seedBuyer(): User
    {
        /** @var User $user */
        $user = User::updateOrCreate(
            ['email' => 'wildanbelfiore@aol.com'],
            [
                'name'              => 'Wildan Jahidson Belfiore',
                'whatsapp'          => '085156334793',
                'password'          => Hash::make('password'),
                'email_verified_at' => now(),
                'trial_ends_at'     => now()->addDays(30),
            ]
        );

        $this->assignRole($user, 'manager');

        $this->command->line("  👤 Buyer  : {$user->name} ({$user->email})");

        return $user;
    }

    // ---------------------------------------------------------------
    // Akun Vendor
    // ---------------------------------------------------------------
    private function seedVendor(): User
    {
        /** @var User $user */
        $user = User::updateOrCreate(
            ['email' => 'anggiseptiani22@outlook.com'],
            [
                'name'              => 'Anggi Septiani',
                'whatsapp'          => '0811144793',
                'password'          => Hash::make('password'),
                'email_verified_at' => now(),
                'trial_ends_at'     => now()->addDays(30),
            ]
        );

        $this->assignRole($user, 'manager');

        $this->command->line("  👤 Vendor : {$user->name} ({$user->email})");

        return $user;
    }

    // ---------------------------------------------------------------
    // Perusahaan Buyer
    // ---------------------------------------------------------------
    private function seedBuyerCompany(User $owner): Company
    {
        /** @var Company $company */
        $company = Company::updateOrCreate(
            ['email' => 'procurement@ptmandirimakmur.co.id'],
            [
                'owner_id'          => $owner->id,
                'name'              => 'PT Mandiri Makmur Sejahtera',
                'type'              => 'buyer',
                'status'            => 'approved',
                'verification_tier' => 'verified',
                'tax_id'            => '01.234.567.8-901.000',
                'country'           => 'ID',
                'email'             => 'procurement@ptmandirimakmur.co.id',
                'phone'             => '02187654321',
                'website'           => 'https://ptmandirimakmur.co.id',
                'region'            => 'Jawa Barat',
                'provincy_country'  => 'Jawa Barat',
                'regency'           => 'Kota Bandung',
                'city'              => 'Bandung',
                'zip_code'          => '40115',
                'address'           => 'Jl. Asia Afrika No. 8, Braga, Kec. Sumur Bandung, Kota Bandung, Jawa Barat',
                'bank_name'         => 'Bank BCA',
                'bank_account'      => '1234567890',
                'bank_account_name' => 'PT Mandiri Makmur Sejahtera',
                'about'             => 'PT Mandiri Makmur Sejahtera adalah perusahaan manufaktur dan distribusi yang bergerak di bidang peralatan industri dan konstruksi. Didirikan sejak 2005, kami telah melayani lebih dari 500 klien korporat di seluruh Indonesia dengan komitmen terhadap kualitas, ketepatan waktu, dan layanan purna jual terbaik.',
                'industry_type'     => 'Manufaktur & Konstruksi',
                'keywords'          => ['industri', 'konstruksi', 'manufaktur', 'peralatan', 'supplier'],
                'hq_addresses'      => [
                    [
                        'label'   => 'Kantor Pusat',
                        'address' => 'Jl. Asia Afrika No. 8, Braga, Bandung 40115',
                        'city'    => 'Bandung',
                        'is_main' => true,
                    ],
                ],
            ]
        );

        $owner->update(['company_id' => $company->id]);

        $this->command->line("  🏢 Buyer Company  : {$company->name}");

        return $company;
    }

    // ---------------------------------------------------------------
    // Perusahaan Vendor
    // ---------------------------------------------------------------
    private function seedVendorCompany(User $owner): Company
    {
        /** @var Company $company */
        $company = Company::updateOrCreate(
            ['email' => 'sales@teknologiprimasupply.co.id'],
            [
                'owner_id'          => $owner->id,
                'name'              => 'CV Teknologi Prima Supply',
                'type'              => 'vendor',
                'status'            => 'approved',
                'verification_tier' => 'standard',
                'tax_id'            => '09.876.543.2-102.000',
                'country'           => 'ID',
                'email'             => 'sales@teknologiprimasupply.co.id',
                'phone'             => '02187651234',
                'website'           => 'https://teknologiprimasupply.co.id',
                'region'            => 'DKI Jakarta',
                'provincy_country'  => 'DKI Jakarta',
                'regency'           => 'Jakarta Selatan',
                'city'              => 'Jakarta',
                'zip_code'          => '12950',
                'address'           => 'Jl. Fatmawati Raya No. 55, Cilandak, Jakarta Selatan, DKI Jakarta',
                'bank_name'         => 'Bank Mandiri',
                'bank_account'      => '1400099887766',
                'bank_account_name' => 'CV Teknologi Prima Supply',
                'about'             => 'CV Teknologi Prima Supply merupakan vendor terpercaya yang mengkhususkan diri dalam penyediaan peralatan IT, elektronik, office supplies, dan alat tulis kantor (ATK) untuk segmen B2B. Dengan pengalaman lebih dari 10 tahun, kami menjamin ketersediaan stok, harga kompetitif, dan pengiriman tepat waktu ke seluruh wilayah Indonesia.',
                'industry_type'     => 'Perdagangan & Distribusi IT',
                'keywords'          => ['IT', 'elektronik', 'laptop', 'printer', 'ATK', 'office supplies', 'komputer'],
                'hq_addresses'      => [
                    [
                        'label'   => 'Kantor & Gudang Utama',
                        'address' => 'Jl. Fatmawati Raya No. 55, Cilandak, Jakarta Selatan 12950',
                        'city'    => 'Jakarta Selatan',
                        'is_main' => true,
                    ],
                ],
            ]
        );

        $owner->update(['company_id' => $company->id]);

        $this->command->line("  🏢 Vendor Company : {$company->name}");

        return $company;
    }

    // ---------------------------------------------------------------
    // Helper: assign role ke user via tabel model_roles
    // ---------------------------------------------------------------
    private function assignRole(User $user, string $roleSlug): void
    {
        $role = Role::where('slug', $roleSlug)->first();

        if (! $role) {
            return;
        }

        $exists = DB::table('model_roles')
            ->where('role_id', $role->id)
            ->where('model_id', $user->id)
            ->where('model_type', User::class)
            ->exists();

        if (! $exists) {
            DB::table('model_roles')->insert([
                'role_id'    => $role->id,
                'model_id'   => $user->id,
                'model_type' => User::class,
            ]);
        }
    }

    // ---------------------------------------------------------------
    // Helper: pastikan buyer punya subscription aktif dengan kuota
    // ---------------------------------------------------------------
    private function fixBuyerSubscription(Company $company): void
    {
        CompanySubscription::updateOrCreate(
            ['company_id' => $company->id],
            [
                'plan'                 => 'saas_subscription',
                'status'               => 'active',
                'overflow_strategy'    => CompanySubscription::OVERFLOW_TRANSACTION_FEE,
                'upfront_fee'          => 0,
                'gmv_limit'            => 9999999999,
                'current_realized_gmv' => 0,
                'reserved_gmv'         => 0,
                'starts_at'            => now(),
                'ends_at'              => now()->addYear(),
                'enterprise_plan'      => 'enterprise_pro',
                'billing_cycle'        => 'annual',
                'is_trial'             => false,
            ]
        );

        $this->command->line("  💳 Subscription : Active Enterprise SaaS ({$company->name})");
    }
}
