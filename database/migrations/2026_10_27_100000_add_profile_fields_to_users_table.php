<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Identity
            $table->string('nik', 20)->nullable()->after('name');
            $table->string('npwp', 20)->nullable()->after('nik');
            $table->string('phone', 30)->nullable()->after('npwp');

            // Personal info
            $table->string('birth_place', 100)->nullable()->after('phone');
            $table->date('birth_date')->nullable()->after('birth_place');
            $table->string('job_title', 100)->nullable()->after('birth_date');

            // Address
            $table->string('country', 100)->nullable()->after('job_title');
            $table->string('province', 100)->nullable()->after('country');
            $table->string('city', 100)->nullable()->after('province');
            $table->string('district', 100)->nullable()->after('city');
            $table->string('postal_code', 10)->nullable()->after('district');
            $table->text('full_address')->nullable()->after('postal_code');

            // Photo
            $table->string('profile_photo_path')->nullable()->after('full_address');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'nik', 'npwp', 'phone',
                'birth_place', 'birth_date', 'job_title',
                'country', 'province', 'city', 'district', 'postal_code', 'full_address',
                'profile_photo_path',
            ]);
        });
    }
};
