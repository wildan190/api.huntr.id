<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enterprise_pricing_announcements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code')->unique();
            $table->timestamp('published_at');
            $table->timestamps();
        });

        Schema::create('enterprise_pricing_announcement_reads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('announcement_id')->constrained('enterprise_pricing_announcements')->cascadeOnDelete();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('acknowledged_at');
            $table->timestamps();
            $table->unique(['announcement_id', 'company_id', 'user_id'], 'pricing_announcement_read_unique');
        });

        $now = now();
        DB::table('enterprise_pricing_announcements')->insert([
            'id' => (string) Str::uuid(),
            'code' => 'enterprise-pricing-v1',
            'published_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_pricing_announcement_reads');
        Schema::dropIfExists('enterprise_pricing_announcements');
    }
};
