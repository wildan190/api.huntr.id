<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value');
            $table->timestamps();
        });

        // Seed default values
        DB::table('admin_settings')->insert([
            ['key' => 'bypass_npwp_verification', 'value' => 'false', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_settings');
    }
};
