<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tiered Verification: Tambah status 'starter' untuk perusahaan
 * yang mendaftar tanpa dokumen/NPWP (quick registration).
 *
 * Status lifecycle:
 *   starter  → Daftar cepat, belum ada NPWP/dokumen. Bisa jelajahi platform.
 *   pending  → Sudah upload NPWP & dokumen, menunggu verifikasi admin.
 *   approved → Sudah diverifikasi admin. Dapat publish RFQ & bertransaksi.
 *   rejected → Ditolak admin. Bisa re-submit dengan data baru.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Add verification_tier column to track completeness
        Schema::table('companies', function (Blueprint $table) {
            if (! Schema::hasColumn('companies', 'verification_tier')) {
                $table->string('verification_tier', 20)->default('basic')->after('status');
                // basic    = starter, no docs
                // standard = docs uploaded, awaiting admin approval
                // verified = admin-approved
            }
        });

        // Migrate existing 'pending' companies with no tax_id to 'starter'
        // (These are companies created before this migration that have no tax_id set)
        DB::table('companies')
            ->where('status', 'pending')
            ->whereNull('tax_id')
            ->update([
                'status'            => 'starter',
                'verification_tier' => 'basic',
            ]);

        // Tag existing approved companies as verified tier
        DB::table('companies')
            ->where('status', 'approved')
            ->update(['verification_tier' => 'verified']);

        // Tag existing pending companies (with tax_id) as standard tier
        DB::table('companies')
            ->where('status', 'pending')
            ->whereNotNull('tax_id')
            ->update(['verification_tier' => 'standard']);
    }

    public function down(): void
    {
        // Revert starter back to pending on rollback
        DB::table('companies')
            ->where('status', 'starter')
            ->update(['status' => 'pending']);

        Schema::table('companies', function (Blueprint $table) {
            if (Schema::hasColumn('companies', 'verification_tier')) {
                $table->dropColumn('verification_tier');
            }
        });
    }
};
